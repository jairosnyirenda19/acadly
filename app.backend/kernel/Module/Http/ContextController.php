<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Grant;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContextController
{
    /**
     * What the screens are built from.
     *
     * The school, its divisions and campuses, the modules that are on, the
     * wording, the account's permissions per scope and the navigation
     * (docs/architecture/03-deployment-campus-access.md §5.8). Divisions,
     * campuses, wording and navigation are empty until the stages that build
     * them. The screens use this to decide what to offer; the server checks
     * every request again.
     *
     * @response array{user: array{id: string, name: string, email: string}, school: array{name: string}, licence: array{status: 'valid'|'expired'|'missing'|'development', read_only: bool}, modules: list<string>, divisions: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>, vocabulary: array<string, string>, navigation: list<array{key: string, permission: string}>, grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>}
     */
    public function __invoke(Request $request, Access $access, ModuleActivation $activation, Licences $licences): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $licence = $licences->current();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'school' => ['name' => config()->string('acadly.school_name')],
            'licence' => ['status' => $licence->status->value, 'read_only' => ! $licence->status->allowsChanges()],
            'modules' => $activation->activeKeys(),
            'divisions' => [],
            'campuses' => [],
            'vocabulary' => (object) [],
            'navigation' => [],
            'grants' => array_map(
                fn (Grant $grant): array => [
                    'permission' => $grant->permission,
                    'scope_type' => $grant->scope->type->value,
                    'scope_id' => $grant->scope->id,
                ],
                $access->grants($user),
            ),
        ]);
    }
}
