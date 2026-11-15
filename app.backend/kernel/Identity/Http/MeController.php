<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Grant;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(private readonly Access $access) {}

    /**
     * The signed-in account and its access.
     *
     * Grants list each permission with the scope it is held at. The screens
     * use them to decide what to offer; the server checks every request again.
     *
     * @response array{user: array{id: string, name: string, email: string}, grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>}
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'grants' => array_map(
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
