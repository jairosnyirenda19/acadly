<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\ProgrammesSlot;
use Acadly\Modules\Programmes\Contracts\StudyShape;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Where Lectures works: one division that studies by programmes. The
 * permission is checked at the division first, so a refusal says nothing
 * about what the division holds.
 */
final class Where
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
    ) {}

    /** Checks that the person holds one of the permissions at the division. */
    public function division(User $who, string $divisionId, LecturesPermission ...$any): DivisionView
    {
        $at = ScopeRef::of(ScopeType::Division, $divisionId);
        $held = false;
        foreach ($any as $permission) {
            $held = $held || $this->access->allows($who, $permission, $at);
        }
        if (! $held) {
            $this->access->check($who, $any[0] ?? LecturesPermission::View, $at);
        }
        $division = $this->school->division($divisionId);
        if ($division === null || $division->profile->binding(ProgrammesSlot::Shape) !== StudyShape::Programmes) {
            throw new NotFoundHttpException;
        }

        return $division;
    }

    public function allows(User $who, string $divisionId, LecturesPermission $permission): bool
    {
        return $this->access->allows($who, $permission, ScopeRef::of(ScopeType::Division, $divisionId));
    }

    /** A row of a Lectures table that belongs to the division, or not found. */
    public static function owned(string $table, string $id, string $divisionId): object
    {
        return DB::table($table)->where('id', $id)->where('division_id', $divisionId)->first() ?? throw new NotFoundHttpException;
    }
}
