<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Access\PermissionCatalogue;
use Acadly\Kernel\Access\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the database into line with the code. Run after every deploy.
 *
 *  - Permissions: the table mirrors the catalogue. New ones are added,
 *    changed ones updated, removed ones deleted (with their role links).
 *  - The system role "administrator" exists and holds every permission.
 */
final class SyncCommand extends Command
{
    protected $signature = 'acadly:sync';

    protected $description = 'Bring permissions and system roles into line with the code';

    public function handle(PermissionCatalogue $catalogue): int
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

        return self::SUCCESS;
    }
}
