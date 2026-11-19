<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Row;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * All learners of the school, read-only, in the whole-school overview (D-100).
 * Only in the school view, for those who may see the overview, and only the
 * divisions whose learners their roles show; every row is grouped under its
 * division and names its campus. Opening a learner moves the console into the
 * learner's division, where the record is read and changed as before.
 */
final class SchoolLearnersController
{
    private const int PER_DIVISION = 50;

    /**
     * The learners of each division, grouped by division, with a search across them.
     *
     * @response array{total: int, groups: list<array{division_id: string, division: string, count: int, shown: int, rows: list<array{id: string, code: string, name: string, class: string, campus: string}>}>, campuses: list<array{id: string, name: string}>}
     */
    public function __invoke(Request $request, Access $access, Boundary $boundary, SchoolProfile $school): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            throw new AuthenticationException;
        }
        if (! $boundary->schoolView()) {
            throw new NotFoundHttpException;
        }
        $access->check($viewer, InstitutionPermission::Overview);
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'division' => ['nullable', 'uuid'], 'campus' => ['nullable', 'uuid']]);

        $q = trim((string) $request->query('q', ''));
        $onlyDivision = $request->query('division');
        $onlyCampus = $request->query('campus');
        $groups = [];
        $total = 0;

        foreach ($school->divisions() as $d) {
            if ((is_string($onlyDivision) && $onlyDivision !== '' && $onlyDivision !== $d['id'])
                || ! $access->holdsForCount($viewer, EnrolmentPermission::View, ScopeRef::of(ScopeType::Division, $d['id']))) {
                continue;
            }
            $base = DB::table('placements as p')->join('learners as l', 'l.id', '=', 'p.learner_id')->join('academic_classes as c', 'c.id', '=', 'p.class_id')
                ->join('institution_campuses as k', 'k.id', '=', 'p.campus_id')->where('p.division_id', $d['id'])->whereNull('p.ends_on')
                ->when(is_string($onlyCampus) && $onlyCampus !== '', fn (Builder $b): Builder => $b->where('p.campus_id', $onlyCampus))
                ->when($q !== '', fn (Builder $b): Builder => $b->where(fn (Builder $w): Builder => $w->whereRaw("(l.first_name || ' ' || l.surname) ilike ?", ['%'.$q.'%'])->orWhere('l.acadly_id', 'ilike', '%'.$q.'%')));
            $count = (clone $base)->count();
            if ($count === 0 && ($q !== '' || is_string($onlyCampus))) {
                continue;
            }
            $rows = [];
            foreach ($base->orderBy('c.position')->orderBy('c.name')->orderBy('l.surname')->orderBy('l.first_name')->limit(self::PER_DIVISION)
                ->get(['l.id', 'l.acadly_id', 'l.first_name', 'l.surname', 'c.name as class', 'k.name as campus']) as $r) {
                $rows[] = ['id' => Row::text($r, 'id'), 'code' => Row::text($r, 'acadly_id'), 'name' => Row::text($r, 'first_name').' '.Row::text($r, 'surname'),
                    'class' => Row::text($r, 'class'), 'campus' => Row::text($r, 'campus')];
            }
            $total += $count;
            $groups[] = ['division_id' => $d['id'], 'division' => $d['name'], 'count' => $count, 'shown' => count($rows), 'rows' => $rows];
        }

        return response()->json(['total' => $total, 'groups' => $groups, 'campuses' => $school->campuses()]);
    }
}
