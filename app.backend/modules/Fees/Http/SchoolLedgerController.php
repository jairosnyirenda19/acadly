<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The fees ledger of the whole school, read-only, in the whole-school overview
 * (D-100): the money received, grouped by finance office and then division.
 * Only in the school view, for those who may see the overview, and only the
 * divisions whose fees their roles show. A payment for several children is
 * shown once in each division it paid into, with that division's share.
 */
final class SchoolLedgerController
{
    private const int LIMIT = 300;

    /**
     * Money received between two days (the last 30 days unless asked), by office and division.
     *
     * @response array{from: string, to: string, total: int, offices: list<array{office_id: string, office: string, total: int, divisions: list<array{division_id: string, division: string, total: int, rows: list<array{payment_id: string, number: string, received_on: string, payer: string, learners: string, campus: string, method: string, amount: int, cancelled: bool}>}>}>}
     */
    public function __invoke(Request $request, Access $access, Boundary $boundary, SchoolProfile $school, FeeOffices $offices, Clock $clock): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer instanceof User) {
            throw new AuthenticationException;
        }
        if (! $boundary->schoolView()) {
            throw new NotFoundHttpException;
        }
        $access->check($viewer, InstitutionPermission::Overview);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $to = self::day($request->query('to')) ?? $clock->today();
        $from = self::day($request->query('from')) ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        $seen = [];
        foreach ($school->divisions() as $d) {
            if ($access->holdsForCount($viewer, FeesPermission::View, ScopeRef::of(ScopeType::Division, $d['id']))) {
                $seen[$d['id']] = $d['name'];
            }
        }
        $offerings = [];
        foreach (DB::table('institution_offerings')->get(['id', 'division_id', 'campus_id']) as $o) {
            $offerings[Row::text($o, 'division_id').'|'.Row::text($o, 'campus_id')] = Row::text($o, 'id');
        }

        $rows = DB::table('fee_payment_shares as sh')->join('fee_payments as p', 'p.id', '=', 'sh.payment_id')->leftJoin('fee_payment_voids as v', 'v.payment_id', '=', 'p.id')
            ->join('learners as l', 'l.id', '=', 'sh.learner_id')
            ->leftJoin('placements as pl', fn (JoinClause $j): JoinClause => $j->on('pl.learner_id', '=', 'sh.learner_id')->whereNull('pl.ends_on'))
            ->leftJoin('institution_campuses as k', 'k.id', '=', 'pl.campus_id')
            ->whereBetween('p.received_on', [$from, $to])->whereIn('pl.division_id', array_keys($seen) ?: ['00000000-0000-0000-0000-000000000000'])
            ->orderByDesc('p.received_on')->orderByDesc('p.number')->limit(self::LIMIT)
            ->get(['p.id', 'p.number', 'p.received_on', 'p.payer_name', 'p.method', 'sh.amount_minor', 'l.first_name', 'l.surname', 'pl.division_id', 'pl.campus_id', 'k.name as campus', 'v.payment_id as voided']);

        /** @var array<string, array{office: string, division: string, line: array{payment_id: string, number: string, received_on: string, payer: string, learners: string, campus: string, method: string, amount: int, cancelled: bool}}> $lines */
        $lines = [];
        $total = 0;
        foreach ($rows as $r) {
            $division = Row::text($r, 'division_id');
            $offering = $offerings[$division.'|'.Row::text($r, 'campus_id')] ?? null;
            $office = $offering === null ? FeeOffices::SCHOOL : $offices->ofOffering($offering);
            $key = $office.'|'.$division.'|'.Row::text($r, 'id');
            $cancelled = Row::maybeText($r, 'voided') !== null;
            $amount = Row::int($r, 'amount_minor');
            $name = Row::text($r, 'first_name').' '.Row::text($r, 'surname');
            if (isset($lines[$key])) {
                $lines[$key]['line']['learners'] .= ', '.$name;
                $lines[$key]['line']['amount'] += $amount;
            } else {
                $lines[$key] = ['office' => $office, 'division' => $division, 'line' => ['payment_id' => Row::text($r, 'id'), 'number' => Row::text($r, 'number'),
                    'received_on' => substr(Row::text($r, 'received_on'), 0, 10), 'payer' => Row::text($r, 'payer_name'), 'learners' => $name, 'campus' => Row::maybeText($r, 'campus') ?? '',
                    'method' => PaymentMethod::tryFrom(Row::text($r, 'method'))?->label() ?? Row::text($r, 'method'), 'amount' => $amount, 'cancelled' => $cancelled]];
            }
            $total += $cancelled ? 0 : $amount;
        }

        $out = [];
        foreach ($offices->offices() as $o) {
            $divisions = [];
            $officeTotal = 0;
            foreach ($seen as $id => $name) {
                $mine = array_values(array_map(fn (array $l): array => $l['line'], array_filter($lines, fn (array $l): bool => $l['office'] === $o['id'] && $l['division'] === $id)));
                if ($mine === []) {
                    continue;
                }
                $sum = array_sum(array_map(fn (array $l): int => $l['cancelled'] ? 0 : $l['amount'], $mine));
                $officeTotal += $sum;
                $divisions[] = ['division_id' => $id, 'division' => $name, 'total' => $sum, 'rows' => $mine];
            }
            if ($divisions !== []) {
                $out[] = ['office_id' => $o['id'], 'office' => $o['name'], 'total' => $officeTotal, 'divisions' => $divisions];
            }
        }

        return response()->json(['from' => $from, 'to' => $to, 'total' => $total, 'offices' => $out]);
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
