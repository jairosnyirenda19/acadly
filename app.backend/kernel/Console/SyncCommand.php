<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Lock;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the database into line with the code. Run after every deploy.
 *
 *  - Permissions: the table mirrors the catalogue. New ones are added,
 *    changed ones updated, removed ones deleted (with their role links).
 *  - The system role "administrator" exists and holds every permission.
 *  - Blueprints: every released version in the lock is recorded in
 *    blueprint_versions, once. A recorded version whose hash differs from
 *    the build stops the sync: a released version never changes.
 */
final class SyncCommand extends Command
{
    protected $signature = 'acadly:sync';

    protected $description = 'Bring permissions, system roles and released blueprints into line with the code';

    public function handle(PermissionCatalogue $catalogue, Blueprints $blueprints, Lock $lock, Clock $clock): int
    {
        $keys = array_keys($catalogue->all());

        [$removed, $total] = DB::transaction(function () use ($catalogue, $keys): array {
            $now = now();
            $rows = [];

            foreach ($catalogue->all() as $key => $permission) {
                $rows[] = [
                    'key' => $key,
                    'owner' => $catalogue->ownerOf($permission),
                    'label' => $permission->label(),
                    'is_sight' => $permission->isSight(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('access_permissions')->upsert($rows, ['key'], ['owner', 'label', 'is_sight', 'updated_at']);
            $removed = DB::table('access_permissions')->whereNotIn('key', $keys)->delete();

            $administrator = Role::query()->firstOrCreate(
                ['key' => Role::ADMINISTRATOR],
                ['name' => 'Administrator', 'description' => 'Every permission. System role.', 'is_system' => true],
            );

            DB::table('access_role_permissions')->where('role_id', $administrator->id)->delete();
            $administrator->grant(array_values($catalogue->all()));

            return [$removed, count($rows)];
        });

        $this->components->info("Permissions in force: {$total}. Removed: {$removed}. The administrator role holds every permission.");

        $recorded = 0;

        foreach ($blueprints->journeys() as $journey) {
            $hash = $lock->hashOf($journey->ref);

            if ($hash === null) {
                continue;
            }

            $known = DB::table('blueprint_versions')->where('ref', (string) $journey->ref)->value('hash');

            if ($known !== null && $known !== $hash) {
                $this->components->error("The released blueprint [{$journey->ref}] differs from the version this deployment recorded.");

                return self::FAILURE;
            }

            if ($known === null) {
                DB::table('blueprint_versions')->insert([
                    'id' => Ids::next(),
                    'kind' => $journey->ref->kind()->value,
                    'ref' => (string) $journey->ref,
                    'key' => $journey->ref->key,
                    'version' => $journey->ref->version,
                    'hash' => $hash,
                    'compiled' => $journey->json(),
                    'released_at' => CarbonImmutable::parse($lock->all()[(string) $journey->ref]['released_at']),
                    'recorded_at' => $clock->now(),
                ]);
                $recorded++;
            }
        }

        $this->components->info("Released blueprint versions recorded now: {$recorded}.");

        return self::SUCCESS;
    }
}
