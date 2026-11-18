<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Identity\AccountProfile;
use Acadly\Kernel\Identity\Actions\ChangePassword;
use Acadly\Kernel\Identity\Actions\TwoStep;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Actions\SetPreference;
use Acadly\Kernel\Notify\Channel;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** Your account (the approved design, D-089). */
final class AccountController
{
    /**
     * Your account.
     *
     * Who you are and your roles, the notices that reach you and on which
     * channels, how you sign in, and the messages sent to you in the last
     * 90 days.
     *
     * @response array{user: array{name: string, email: string|null, phone: string|null}, roles: list<array{role: string, where: string}>, notices: list<array{key: string, name: string, category: string|null, locked: bool, channels: list<array{channel: 'in_app'|'email'|'sms', on: bool}>}>, security: array{password_changed_at: string|null, two_step: bool, two_step_since: string|null, recovery_left: int}, messages: list<array{id: string, channel: 'in_app'|'email'|'sms', text: string, status: string, at: string}>}
     */
    public function show(Request $request, AccountProfile $profile): JsonResponse
    {
        return response()->json($profile->show(self::user($request)));
    }

    /** Whether a notice reaches you on a channel: `{topic, channel, on}`. The response is your account. */
    public function notice(Request $request, SetPreference $set, AccountProfile $profile): JsonResponse
    {
        $channel = Channel::tryFrom($request->string('channel')->toString()) ?? throw ValidationException::withMessages(['channel' => ['Choose a channel.']]);
        $set->handle(self::user($request), $request->string('topic')->toString(), $channel, $request->boolean('on'));

        return $this->show($request, $profile);
    }

    /** Changes your password: `{current_password, password, password_confirmation}`. */
    public function password(Request $request, ChangePassword $change): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()]]);
        $change->handle(self::user($request), $request->string('current_password')->toString(), $request->string('password')->toString());

        return response()->json(['changed' => true]);
    }

    /**
     * Starts turning on two-step sign-in: the key to add to an authenticator app.
     *
     * @response array{key: string, address: string}
     */
    public function twoStepBegin(Request $request, TwoStep $twoStep): JsonResponse
    {
        return response()->json($twoStep->begin(self::user($request)));
    }

    /**
     * Turns on two-step sign-in with a code from the app: `{code}`. The ten recovery codes are shown once.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function twoStepConfirm(Request $request, TwoStep $twoStep): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        return response()->json(['recovery_codes' => $twoStep->confirm(self::user($request), $request->string('code')->toString())]);
    }

    /** Turns off your two-step sign-in, with your password: `{password}`. The response is your account. */
    public function twoStepOff(Request $request, TwoStep $twoStep, AccountProfile $profile): JsonResponse
    {
        $user = self::user($request);
        $twoStep->turnOff($user, $user, $request->string('password')->toString());

        return $this->show($request, $profile);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
