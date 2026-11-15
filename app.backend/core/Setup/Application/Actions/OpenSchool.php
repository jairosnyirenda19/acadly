<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Core\Setup\Application\SetupProgress;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Identity\User;
use Illuminate\Validation\ValidationException;

/**
 * Opens the school once every required step is done and an administrator
 * holds the whole school. The school code is fixed from then on.
 */
#[NotAudited('The Institution directory records the opening when it writes it.')]
final class OpenSchool
{
    private const array SAY = [
        'school' => 'The school details are not complete.',
        'levels' => 'Every level must be taught at a campus.',
        'year' => 'The academic year, or the terms of a level, are not set.',
        'classes' => 'Every level at every campus needs at least one class.',
        'administrator' => 'An administrator must hold the whole school.',
    ];

    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly SetupProgress $progress,
    ) {}

    public function handle(User $by): void
    {
        $this->access->check($by, InstitutionPermission::Manage);

        $blockers = $this->progress->blockers();

        if ($blockers !== []) {
            throw ValidationException::withMessages(['setup' => array_map(fn (string $step): string => self::SAY[$step] ?? $step, $blockers)]);
        }

        $this->school->open($by);
    }
}
