<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Grant;
use Acadly\Kernel\Identity\SignInMethod;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Identity\ViewAs;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(
        private readonly Access $access,
        private readonly ViewAs $viewAs,
    ) {}

    /**
     * The signed-in account and its access.
     *
     * Grants list each permission with the scope it is held at. The screens
     * use them to decide what to offer; the server checks every request again.
     *
     * `view_as` says whether this account may view as others (development
     * only), and, while it does, who is really signed in and when it ends.
     *
     * `signed_in_by` is `code` when the session was signed in with a code by
     * SMS: it reaches the parent portal only, and holds no grants (D-087).
     *
     * @response array{user: array{id: string, name: string, email: string|null, phone: string|null}, signed_in_by: 'password'|'code', grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>, view_as: array{available: bool, by: array{id: string, name: string}|null, ends_at: string|null}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $by = $this->viewAs->by($request);
        $code = SignInMethod::byCode($request, $user);

        return response()->json([
            'view_as' => [
                'available' => $by !== null || $this->viewAs->mayStart($user),
                'by' => $by !== null ? ['id' => $by->id, 'name' => $by->name] : null,
                'ends_at' => $by !== null ? $this->viewAs->endsAt($request)?->toIso8601String() : null,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'signed_in_by' => $code ? 'code' : 'password',
            'grants' => $code ? [] : array_map(
                fn (Grant $grant): array => [
                    'permission' => $grant->permission,
                    'scope_type' => $grant->scope->type->value,
                    'scope_id' => $grant->scope->id,
                ],
                $this->access->grants($user),
            ),
        ]);
    }
}
