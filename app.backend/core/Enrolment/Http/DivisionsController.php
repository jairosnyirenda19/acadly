<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Notify\Messages;
use Acadly\Kernel\Notify\NotifyPermission;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The Divisions start screen (D-096). */
final class DivisionsController
{
    /**
     * The divisions the account works in, with today's figures, and the tiles the school shares.
     *
     * Each figure is there only when the account may see it. No money and no learner names.
     *
     * @response array{day: string, divisions: list<array{id: string, name: string, profile: string, learners: int|null, word: string, present: int|null, marked: int|null, states: list<array{tone: 'done'|'attention'|'wrong', text: string}>}>, school: array{present: array{present: int, marked: int}|null, approvals: int|null, messages: array{sent: int, attention: int}|null, term: array{name: string, ends_on: string, days_left: int}|null, staff: int|null}}
     */
    public function __invoke(Request $request, SchoolProfile $school, Access $access, ModuleActivation $activation, Clock $clock, Teaching $teaching, ApprovalRequests $approvals, Messages $messages): JsonResponse
    {
        $viewer = $request->user();

        if (! $viewer instanceof User) {
            throw new AuthenticationException;
        }

        $day = $clock->today();
        $reach = $this->reach($access, $viewer, array_column($school->divisions(), 'id'));
        $all = [];

        foreach (app()->tagged(DivisionFigures::TAG) as $source) {
            if ($source instanceof DivisionFigures && $activation->isActive($source->key())) {
                foreach ($source->figures($viewer, $reach, $day) as $id => $f) {
                    $all[$id][] = $f;
                }
            }
        }

        $divisions = [];
        $present = 0;
        $marked = 0;
        $anyAttendance = false;

        foreach ($school->divisions() as $d) {
            if (! in_array($d['id'], $reach, true)) {
                continue;
            }

            $row = ['id' => $d['id'], 'name' => $d['name'], 'profile' => $d['profile'], 'learners' => null, 'word' => $d['vocabulary']['learner']['many'] ?? 'Learners',
                'present' => null, 'marked' => null, 'states' => []];

            foreach ($all[$d['id']] ?? [] as $f) {
                $row['learners'] = $f['learners'] ?? $row['learners'];

                if (isset($f['marked'])) {
                    $row['present'] = $f['present'] ?? 0;
                    $row['marked'] = $f['marked'];
                    $present += $row['present'];
                    $marked += $f['marked'];
                    $anyAttendance = true;
                }

                if (isset($f['state'])) {
                    $row['states'][] = $f['state'];
                }
            }

            // Attention first: what is waiting comes before what is done.
            usort($row['states'], fn (array $a, array $b): int => ($a['tone'] === 'done' ? 1 : 0) <=> ($b['tone'] === 'done' ? 1 : 0));
            $divisions[] = $row;
        }

        $term = null;
        $first = $reach[0] ?? null;
        $period = $first === null ? null : $teaching->periodOn($first, $day);

        if ($period !== null) {
            $term = ['name' => $period['name'], 'ends_on' => $period['ends_on'], 'days_left' => max(0, (int) CarbonImmutable::parse($day)->diffInDays(CarbonImmutable::parse($period['ends_on'])))];
        }

        $sent = null;

        if ($access->allows($viewer, NotifyPermission::Manage)) {
            $totals = $messages->sent($day, null, null, null)['totals'];
            $sent = ['sent' => $totals['sent'], 'attention' => $totals['attention']];
        }

        return response()->json([
            'day' => $day,
            'divisions' => $divisions,
            'school' => [
                'present' => $anyAttendance ? ['present' => $present, 'marked' => $marked] : null,
                'approvals' => count($approvals->waitingFor($viewer)),
                'messages' => $sent,
                'term' => $term,
                'staff' => $access->allows($viewer, AccessPermission::AssignRoles)
                    ? DB::table('access_role_assignments')->where('starts_at', '<=', now())->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->distinct()->count('user_id')
                    : null,
            ],
        ]);
    }

    /**
     * The divisions the account works in: every one from a role held for the
     * whole school, otherwise the division of each place it holds a role in.
     *
     * @param  list<string>  $divisionIds
     * @return list<string>
     */
    private function reach(Access $access, User $viewer, array $divisionIds): array
    {
        $found = [];

        foreach ($access->grants($viewer) as $grant) {
            $id = $grant->scope->id;

            $found = [...$found, ...match ($grant->scope->type) {
                ScopeType::Institution => $divisionIds,
                ScopeType::Division => $id === null ? [] : [$id],
                ScopeType::Campus => $id === null ? [] : array_values(array_filter(DB::table('institution_offerings')->where('campus_id', $id)->whereNull('ended_at')->pluck('division_id')->all(), 'is_string')),
                ScopeType::Offering => $id === null ? [] : array_filter([DB::table('institution_offerings')->where('id', $id)->value('division_id')], 'is_string'),
                ScopeType::ClassGroup => $id === null ? [] : array_filter([DB::table('academic_classes')->where('id', $id)->value('division_id')], 'is_string'),
            }];
        }

        return array_values(array_filter($divisionIds, fn (string $d): bool => in_array($d, $found, true)));
    }
}
