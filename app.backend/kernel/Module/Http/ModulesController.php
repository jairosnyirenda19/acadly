<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Actions\SwitchModule;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModulePermission;
use Acadly\Kernel\Module\ModuleRegistry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModulesController
{
    /**
     * The modules in this build and whether each is on.
     *
     * `state` names the first condition that is not met: `not_offered`,
     * `not_licensed` or `switched_off`.
     *
     * @response array{licence: array{status: 'valid'|'expired'|'missing'|'development', licensee: string|null, expires_at: string|null}, modules: list<array{key: string, name: string, tier: 'core'|'optional', state: 'active'|'not_installed'|'not_offered'|'not_licensed'|'switched_off', switched_on: bool}>}
     */
    public function index(Request $request, Access $access, ModuleRegistry $modules, ModuleActivation $activation, Licences $licences): JsonResponse
    {
        $access->check(self::user($request), ModulePermission::Manage);
        $licence = $licences->current();
        $list = [];

        foreach ($modules->all() as $module) {
            $list[] = [
                'key' => $module->key(),
                'name' => $module->name(),
                'tier' => $module->tier()->value,
                'state' => $activation->state($module->key())->value,
                'switched_on' => $activation->isSwitchedOn($module->key()),
            ];
        }

        return response()->json([
            'licence' => [
                'status' => $licence->status->value,
                'licensee' => $licence->licensee,
                'expires_at' => $licence->expiresAt?->toIso8601String(),
            ],
            'modules' => $list,
        ]);
    }

    /**
     * Switch a module on or off.
     *
     * A reason is required and is kept in the audit trail.
     */
    public function update(Request $request, string $key, SwitchModule $switch): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $switch->handle(self::user($request), $key, $request->boolean('enabled'), $request->string('reason')->toString());

        return response()->json(['key' => $key, 'enabled' => $request->boolean('enabled')]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
