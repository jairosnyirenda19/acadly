<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Lock;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\NoticeCatalogue;
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
 *  - Notices: notification_topics mirrors the catalogue, and the catalogue
 *    hash is stored for the readiness probe (04 §9).
 *  - Blueprints: every released version in the lock is recorded in
 *    blueprint_versions, once. A recorded version whose hash differs from
 *    the build stops the sync: a released version never changes.
 */
final class SyncCommand extends Command
{
    protected $signature = 'acadly:sync';

    protected $description = 'Bring permissions, system roles, notices and released blueprints into line with the code';

    public function handle(PermissionCatalogue $catalogue, NoticeCatalogue $notices, Blueprints $blueprints, Lock $lock, Clock $clock): int
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

        $topics = DB::transaction(function () use ($notices, $clock): int {
            $rows = [];

            foreach ($notices->all() as $key => $notice) {
                $rows[] = [
                    'key' => $key,
                    'owner' => $notices->ownerOf($key),
                    'name' => $notice->name(),
                    'category' => (string) $notice->categoryOf()?->value,
                    'desk' => $notice->hasDesk(),
                    'acted_on_with' => json_encode($notice->actingPermissions(), JSON_THROW_ON_ERROR),
                    'default_channels' => json_encode(array_map(fn (Channel $c): string => $c->value, $notice->defaultChannels()), JSON_THROW_ON_ERROR),
                    'allowed_channels' => json_encode(array_map(fn (Channel $c): string => $c->value, $notice->allowedChannels()), JSON_THROW_ON_ERROR),
                    'updated_at' => $clock->now(),
                ];
            }

            DB::table('notification_topics')->upsert($rows, ['key'], ['owner', 'name', 'category', 'desk', 'acted_on_with', 'default_channels', 'allowed_channels', 'updated_at']);
            DB::table('notification_topics')->whereNotIn('key', array_keys($notices->all()))->delete();
            DB::table('system_sync_marks')->upsert(
                [['name' => 'notify.catalogue', 'hash' => $notices->hash(), 'synced_at' => $clock->now()]],
                ['name'], ['hash', 'synced_at'],
            );

            return count($rows);
        });

        $this->components->info("Notices in force: {$topics}.");

        $recorded = 0;

        foreach ($blueprints->all() as $blueprint) {
            $ref = $blueprint->reference();
            $hash = $lock->hashOf($ref);

            if ($hash === null) {
                continue;
            }

            $known = DB::table('blueprint_versions')->where('ref', (string) $ref)->value('hash');

            if ($known !== null && $known !== $hash) {
                $this->components->error("The released blueprint [{$ref}] differs from the version this deployment recorded.");

                return self::FAILURE;
            }

            if ($known === null) {
                DB::table('blueprint_versions')->insert([
                    'id' => Ids::next(),
                    'kind' => $ref->kind()->value,
                    'ref' => (string) $ref,
                    'key' => $ref->key,
                    'version' => $ref->version,
                    'hash' => $hash,
                    'compiled' => $blueprint->json(),
                    'released_at' => CarbonImmutable::parse($lock->all()[(string) $ref]['released_at']),
                    'recorded_at' => $clock->now(),
                ]);
                $recorded++;
            }
        }

        $this->components->info("Released blueprint versions recorded now: {$recorded}.");

        return self::SUCCESS;
    }
}
