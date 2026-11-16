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
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ModulesController
{
    /**
     * The modules in this build and whether each is on.
     *
     * `state` names the first condition that is not met: `not_offered`,
     * `not_licensed` or `switched_off`.
     *
     * @response array{licence: array{status: 'valid'|'expired'|'missing'|'development', licensee: string|null, expires_at: string|null}, modules: list<array{key: string, name: string, summary: string, needs: list<string>, tier: 'core'|'optional', state: 'active'|'not_installed'|'not_offered'|'not_licensed'|'switched_off', switched_on: bool, last_switch: array{at: string, by: string|null, enabled: bool, reason: string}|null}>, history: list<array{key: string, name: string, enabled: bool, at: string, by: string|null, reason: string}>}
     */
    public function index(Request $request, Access $access, ModuleRegistry $modules, ModuleActivation $activation, Licences $licences): JsonResponse
    {
        $access->check(self::user($request), ModulePermission::Manage);
        $licence = $licences->current();
        $list = [];
        $names = [];

        foreach ($modules->all() as $module) {
            $names[$module::class] = $module->name();
        }

        $switches = DB::table('module_switches as s')->leftJoin('users as u', 'u.id', '=', 's.switched_by')
            ->orderByDesc('s.switched_at')->limit(200)->get(['s.module_key', 's.enabled', 's.reason', 's.switched_at', 'u.name as by']);
        $history = [];
        $last = [];

        foreach ($switches as $row) {
            $key = Row::text($row, 'module_key');
            $entry = ['at' => Row::text($row, 'switched_at'), 'by' => Row::maybeText($row, 'by'), 'enabled' => (bool) $row->enabled, 'reason' => Row::text($row, 'reason')];
            $last[$key] ??= $entry;

            if (count($history) < 10 && $modules->has($key)) {
                $history[] = ['key' => $key, 'name' => $modules->get($key)->name(), ...$entry];
            }
        }

        foreach ($modules->all() as $module) {
            $list[] = [
                'key' => $module->key(),
                'name' => $module->name(),
                'summary' => $module->summary(),
                'needs' => array_map(fn (string $class): string => $names[$class] ?? '', $module->requires()),
                'tier' => $module->tier()->value,
                'state' => $activation->state($module->key())->value,
                'switched_on' => $activation->isSwitchedOn($module->key()),
                'last_switch' => $last[$module->key()] ?? null,
            ];
        }

        return response()->json([
            'licence' => [
                'status' => $licence->status->value,
                'licensee' => $licence->licensee,
                'expires_at' => $licence->expiresAt?->toIso8601String(),
            ],
            'modules' => $list,
            'history' => $history,
        ]);
    }

    /**
     * Switch a module on or off.
     *
     * A reason is required and is kept in the audit trail.
     */
    public function update(Request $request, string $key, SwitchModule $switch, Access $access): JsonResponse
    {
        $access->check(self::user($request), ModulePermission::Manage);
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
