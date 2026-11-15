<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Events\Outbox;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\Events\ModuleSwitched;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Module\ModuleOffering;
use Acadly\Kernel\Module\ModulePermission;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Module\Tier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Switches an optional module on or off for the school, with a reason.
 *
 * Guard rails:
 *  - core functions are always on;
 *  - a module goes on only if the licence includes it, the profile offers it,
 *    and every module it needs is already on;
 *  - a module goes off only if the profile does not require it and no module
 *    that is on still needs it.
 *
 * Switching off hides nothing for ever: the records stay, and the module's
 * permission codes stay in the catalogue so the roles survive (03 §5.7 rule 4).
 */
final class SwitchModule
{
    public function __construct(
        private readonly Access $access,
        private readonly ModuleRegistry $modules,
        private readonly ModuleActivation $activation,
        private readonly ModuleOffering $offering,
        private readonly Licences $licences,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, string $key, bool $enabled, string $reason): void
    {
        $this->access->check($by, ModulePermission::Manage);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        if (! $this->modules->has($key)) {
            throw ValidationException::withMessages(['module' => ['The module is not part of this build.']]);
        }

        $module = $this->modules->get($key);
        $here = ScopeRef::institution();

        if ($module->tier() === Tier::Core) {
            throw ValidationException::withMessages(['module' => ['Core functions are always on.']]);
        }

        if ($this->activation->isSwitchedOn($key) === $enabled) {
            throw ValidationException::withMessages(['module' => [$enabled ? 'The module is already on.' : 'The module is already off.']]);
        }

        $enabled ? $this->assertCanSwitchOn($key) : $this->assertCanSwitchOff($key, $here);

        DB::transaction(function () use ($by, $key, $enabled, $reason): void {
            DB::table('module_switches')->insert([
                'id' => Ids::next(),
                'module_key' => $key,
                'enabled' => $enabled,
                'reason' => $reason,
                'switched_by' => $by->id,
                'switched_at' => $this->clock->now(),
            ]);

            $this->audit->record(KernelAudit::ModuleSwitched, reason: $reason, actor: $this->audit->actorFor($by),
                meta: ['module' => $key, 'enabled' => $enabled]);

            $this->outbox->record(new ModuleSwitched($key, $enabled, $by->id));
        });

        $this->activation->forget();
    }

    private function assertCanSwitchOn(string $key): void
    {
        $module = $this->modules->get($key);

        if (! $this->licences->current()->includes($key)) {
            throw ValidationException::withMessages(['module' => ['The licence does not include this module.']]);
        }

        if (! $this->offering->offers($module, ScopeRef::institution())) {
            throw ValidationException::withMessages(['module' => ['The school profile does not offer this module.']]);
        }

        foreach ($module->requires() as $required) {
            foreach ($this->modules->all() as $candidate) {
                if ($candidate::class === $required && ! $this->activation->isActive($candidate->key())) {
                    throw ValidationException::withMessages(['module' => ["Switch on {$candidate->name()} first."]]);
                }
            }
        }
    }

    private function assertCanSwitchOff(string $key, ScopeRef $here): void
    {
        $module = $this->modules->get($key);

        if ($this->offering->requires($module, $here)) {
            throw ValidationException::withMessages(['module' => ['The school profile requires this module.']]);
        }

        foreach ($this->modules->all() as $other) {
            if ($other->key() !== $key && in_array($module::class, $other->requires(), true) && $this->activation->isActive($other->key())) {
                throw ValidationException::withMessages(['module' => ["Switch off {$other->name()} first."]]);
            }
        }
    }
}
