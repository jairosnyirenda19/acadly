<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Application;

use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\Role;

/**
 * The roles a new school starts with. A role is made the first time a person
 * is invited with it, and from then on it belongs to the school: it is never
 * changed here again. Each grows its permissions as the modules that give
 * them are built; Profile Blueprints take over this list when role templates
 * are added to them.
 */
final class StarterRoles
{
    /** @var array<string, array{name: string, scope: string, description: string}> */
    public const array ALL = [
        'headteacher' => ['name' => 'Headteacher', 'scope' => 'A level, or the school', 'description' => 'Leads a level or the whole school.'],
        'academic_officer' => ['name' => 'Academic officer', 'scope' => 'A level', 'description' => 'Runs the calendar, classes and results of a level.'],
        'admissions_officer' => ['name' => 'Admissions officer', 'scope' => 'Whole school', 'description' => 'Receives and decides applications.'],
        'class_teacher' => ['name' => 'Class teacher', 'scope' => 'A level and campus', 'description' => 'Keeps the register and the record of a class.'],
        'subject_teacher' => ['name' => 'Subject teacher', 'scope' => 'A level and campus', 'description' => 'Teaches and assesses a subject.'],
    ];

    /** @return list<Permission> what a starter role holds now */
    public static function permissions(string $key): array
    {
        return match ($key) {
            'headteacher' => [InstitutionPermission::Manage, AcademicsPermission::Manage],
            'academic_officer' => [AcademicsPermission::Manage],
            default => [],
        };
    }

    /** The role with this key: the school's own, or a starter role made now. */
    public static function ensure(string $key): ?Role
    {
        $role = Role::query()->where('key', $key)->first();

        if ($role instanceof Role) {
            return $role;
        }

        $starter = self::ALL[$key] ?? null;

        if ($starter === null) {
            return null;
        }

        $role = Role::query()->create(['key' => $key, 'name' => $starter['name'], 'description' => $starter['description'], 'is_system' => false]);
        $role->grant(self::permissions($key));

        return $role;
    }
}
