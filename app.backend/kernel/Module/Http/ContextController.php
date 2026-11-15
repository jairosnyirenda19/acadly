<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Grant;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
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
     * (docs/architecture/03-deployment-campus-access.md §5.8). `setup_required`
     * holds until the school is opened. `vocabulary` is the words of the only
     * division when there is one; with several, each division carries its own.
     * Navigation is empty until the screens that fill it. The screens use this
     * to decide what to offer; the server checks every request again.
     *
     * @response array{user: array{id: string, name: string, email: string}, school: array{name: string, short_name: string, has_logo: bool, open: bool}, setup_required: bool, licence: array{status: 'valid'|'expired'|'missing'|'development', read_only: bool}, modules: list<string>, divisions: list<array{id: string, name: string, vocabulary: array<string, array{one: string, many: string}>}>, campuses: list<array{id: string, name: string}>, vocabulary: array<string, array{one: string, many: string}>, navigation: list<array{key: string, permission: string}>, grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>}
     */
    public function __invoke(Request $request, Access $access, ModuleActivation $activation, Licences $licences, SchoolProfile $school): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $licence = $licences->current();
        $divisions = $school->divisions();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'school' => ['name' => $school->name(), 'short_name' => $school->shortName(), 'has_logo' => $school->hasLogo(), 'open' => $school->isOpen()],
            'setup_required' => ! $school->isOpen(),
            'licence' => ['status' => $licence->status->value, 'read_only' => ! $licence->status->allowsChanges()],
            'modules' => $activation->activeKeys(),
            'divisions' => $divisions,
            'campuses' => $school->campuses(),
            'vocabulary' => (object) (count($divisions) === 1 ? $divisions[0]['vocabulary'] : []),
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
