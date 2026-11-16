<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Actions\MessageActions;
use Acadly\Kernel\Notify\Actions\RecordDeliveryReport;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Messages;
use Acadly\Kernel\Notify\NotifyPermission;
use Acadly\Kernel\Support\Clock;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The Messages pages (the approved designs): channels, wording and sent messages. */
final class MessagesController
{
    public function __construct(
        private readonly Access $access,
        private readonly Messages $messages,
        private readonly MessageActions $actions,
    ) {}

    /**
     * How messages are sent, and this month's numbers.
     *
     * @response array{sms: array{provider: string|null, providers: list<array{key: string, name: string, needs_username: bool}>, test_mode: bool, sender: string|null, username: string|null, key_hint: string|null, connected: bool, last_accepted_at: string|null, reports_address: string|null, balance: array{value: string, at: string}|null}, email: array{from_address: string, from_name: string, reply_to: string|null, connected: bool}, limits: array{quiet_from: string|null, quiet_to: string|null, sms_per_month: int|null}, month: array{sms: int, units: int, delivered_share: int|null, emails: int, emails_accepted: int, not_delivered: int}}
     */
    public function channels(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);

        return response()->json($this->messages->channels());
    }

    public function saveSms(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['provider' => ['required', 'string'], 'sender' => ['nullable', 'string', 'max:20'], 'username' => ['nullable', 'string', 'max:80'], 'key' => ['nullable', 'string', 'max:500']]);
        $this->actions->saveSms(self::user($request), $request->string('provider')->toString(), self::text($request, 'sender'), self::text($request, 'username'), self::text($request, 'key'));

        return $this->channels($request);
    }

    public function saveEmail(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['from_name' => ['required', 'string', 'max:200'], 'reply_to' => ['nullable', 'string', 'max:190']]);
        $this->actions->saveEmail(self::user($request), $request->string('from_name')->toString(), self::text($request, 'reply_to'));

        return $this->channels($request);
    }

    public function saveLimits(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['quiet_from' => ['nullable', 'string'], 'quiet_to' => ['nullable', 'string'], 'sms_per_month' => ['nullable', 'integer']]);
        $cap = $request->input('sms_per_month');
        $this->actions->saveLimits(self::user($request), self::text($request, 'quiet_from'), self::text($request, 'quiet_to'), is_numeric($cap) ? (int) $cap : null);

        return $this->channels($request);
    }

    /** @response array{id: string} */
    public function test(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['channel' => ['required', 'in:sms,email'], 'address' => ['required', 'string', 'max:190']]);
        $id = $this->actions->test(self::user($request), Channel::from($request->string('channel')->toString()), $request->string('address')->toString());

        return response()->json(['id' => $id]);
    }

    /**
     * Every notice, and whether the school changed it.
     *
     * @response array{notices: list<array{key: string, name: string, group: string, audience: 'contacts'|'staff', channels: list<string>, enabled: bool, own_words: bool}>}
     */
    public function notices(Request $request): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);

        return response()->json(['notices' => $this->messages->notices()]);
    }

    /**
     * One notice: the declared words, the school's own, and what it may use.
     *
     * @response array{key: string, name: string, audience: 'contacts'|'staff', channels: list<string>, enabled: bool, can_switch_off: bool, contacts: 'main'|'every'|null, facts: list<string>, examples: array<string, string|null>, declared: array{subject: string, lines: list<string>, short: string|null}, own: array{subject: string|null, lines: list<string>|null, short: string|null}|null, budget: int, short_length: array{characters: int, units: int, unicode: bool}|null}
     */
    public function notice(Request $request, string $key): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);

        return response()->json($this->messages->notice($key) ?? abort(404));
    }

    public function saveNotice(Request $request, string $key): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['subject' => ['nullable', 'string', 'max:300'], 'lines' => ['nullable', 'array', 'max:12'], 'lines.*' => ['string', 'max:600'],
            'short' => ['nullable', 'string', 'max:400'], 'enabled' => ['required', 'boolean'], 'contacts' => ['nullable', 'in:main,every']]);
        $lines = $request->input('lines');
        $this->actions->saveNotice(self::user($request), $key, self::text($request, 'subject'),
            is_array($lines) ? array_values(array_filter($lines, 'is_string')) : null, self::text($request, 'short'), $request->boolean('enabled'), self::text($request, 'contacts'));

        return $this->notice($request, $key);
    }

    public function resetNotice(Request $request, string $key): JsonResponse
    {
        $this->actions->resetWording(self::user($request), $key);

        return $this->notice($request, $key);
    }

    /**
     * Every message of one day, newest first.
     *
     * @response array{day: string, today: string, totals: array{sent: int, delivered: int, waiting: int, attention: int}, messages: list<array{id: string, at: string, to: string, address: string|null, notice: string, about: string, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable'|'over_allowance'|'not_sent', reason: string|null, units: int, again: bool}>}
     */
    public function sent(Request $request, Clock $clock): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);
        $request->validate(['day' => ['nullable', 'date_format:Y-m-d'], 'channel' => ['nullable', 'string'], 'state' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:120']]);

        return response()->json($this->messages->sent(self::text($request, 'day') ?? $clock->today(), self::text($request, 'channel'), self::text($request, 'state'), self::text($request, 'q')));
    }

    /**
     * One message, its words and what happened to it.
     *
     * @response array{id: string, at: string, to: string, address: string|null, notice: string, about: string, channel: string, state: string, reason: string|null, units: int, again: bool, text: string, email_subject: string|null, provider: string|null, steps: list<array{at: string, what: 'written'|'accepted'|'refused'|'delivered'|'not_delivered'|'not_reachable'|'over_allowance'}>, sent_again: array{id: string, state: string}|null, can_send_again: bool}
     */
    public function message(Request $request, string $id): JsonResponse
    {
        $this->access->check(self::user($request), NotifyPermission::Manage);

        return response()->json($this->messages->message($id) ?? abort(404));
    }

    public function again(Request $request, string $id): JsonResponse
    {
        $this->actions->again(self::user($request), $id);

        return $this->message($request, $id);
    }

    /** A provider's report on a message. Always answered the same way, so the address reveals nothing. */
    public function report(Request $request, string $token, RecordDeliveryReport $record): Response
    {
        $record->handle($token, $request);

        return response()->noContent();
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
