<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Conduct\Application\ConductReach;
use Acadly\Modules\Conduct\Contracts\ConductAudit;
use Acadly\Modules\Conduct\Contracts\ConductPermission;
use Acadly\Modules\Conduct\Domain\ConductCase;
use Acadly\Modules\Conduct\Domain\Level;
use Acadly\Modules\Conduct\Journeys\SeriousIncident\State;
use Acadly\Modules\Conduct\Notifications\ConductRecorded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records an incident or a commendation for one or more learners (D-092).
 * An entry never changes: a mistake is withdrawn with a reason. A serious
 * incident opens a case that the office follows up.
 */
final class EntryActions
{
    public const int MAX_LEARNERS = 30;

    public function __construct(
        private readonly ConductReach $reach,
        private readonly Blueprints $blueprints,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<string>  $learners
     * @return array{entry_id: string, case_id: string|null}
     */
    public function record(User $by, string $kindId, ?Level $level, array $learners, string $happenedOn, ?string $place, string $what, ?string $action, bool $tell): array
    {
        $kind = DB::table('conduct_kinds')->where('id', $kindId)->whereNull('retired_at')->first(['id', 'name', 'commendation', 'level', 'tell']);

        if ($kind === null) {
            throw ValidationException::withMessages(['kind_id' => ['Choose what kind it is.']]);
        }

        $learners = array_values(array_unique($learners));

        if ($learners === [] || count($learners) > self::MAX_LEARNERS) {
            throw ValidationException::withMessages(['learners' => ['Choose from 1 to '.self::MAX_LEARNERS.' learners.']]);
        }

        $classes = [];

        foreach ($learners as $learner) {
            $at = $this->reach->learner($by, $learner, ConductPermission::Record);
            $classes[$learner] = (string) $at->id;
        }

        $what = trim($what);
        $today = $this->clock->today();

        if ($what === '') {
            throw ValidationException::withMessages(['what' => ['Say what happened.']]);
        }
        if ($happenedOn > $today || $happenedOn < CarbonImmutable::parse($today)->subDays(60)->toDateString()) {
            throw ValidationException::withMessages(['happened_on' => ['Give a day in the last 60 days.']]);
        }

        $commendation = (bool) $kind->commendation;
        $level = $commendation ? null : ($level ?? Level::tryFrom((string) Row::maybeText($kind, 'level')) ?? Level::Minor);
        // Commendations and serious incidents are always told when the kind says so; a serious incident always.
        $told = $level === Level::Serious || Row::text($kind, 'tell') === 'always' || $tell;

        return DB::transaction(function () use ($by, $kind, $commendation, $level, $learners, $classes, $happenedOn, $place, $what, $action, $told): array {
            $id = Ids::next();
            $now = $this->clock->now();
            DB::table('conduct_entries')->insert([
                'id' => $id, 'kind_id' => Row::text($kind, 'id'), 'kind_name' => Row::text($kind, 'name'), 'commendation' => $commendation, 'level' => $level?->value,
                'happened_on' => $happenedOn, 'place' => $place !== null && trim($place) !== '' ? mb_substr(trim($place), 0, 120) : null, 'what' => mb_substr($what, 0, 4000),
                'action' => $action !== null && trim($action) !== '' ? mb_substr(trim($action), 0, 2000) : null, 'told_family' => $told, 'recorded_by' => $by->id, 'created_at' => $now,
            ]);
            $rows = [];

            foreach ($learners as $learner) {
                $rows[$learner] = Ids::next();
                DB::table('conduct_entry_learners')->insert(['id' => $rows[$learner], 'entry_id' => $id, 'learner_id' => $learner, 'class_id' => $classes[$learner]]);
            }

            $case = null;

            if ($level === Level::Serious) {
                $case = ConductCase::query()->create([
                    'number' => self::number($happenedOn), 'entry_id' => $id, 'class_id' => $classes[$learners[0]], 'lead_by' => null,
                    'state' => State::Reported->value, 'journey_ref' => (string) $this->blueprints->newest('conduct.serious_incident'),
                ]);
                DB::table('conduct_case_notes')->insert(['id' => Ids::next(), 'case_id' => $case->id, 'text' => 'Reported.', 'written_by' => $by->id, 'created_at' => $now]);
            }

            $this->audit->record(ConductAudit::Recorded, actor: $this->audit->actorFor($by),
                meta: ['entry_id' => $id, 'kind' => Row::text($kind, 'name'), 'level' => $level === null ? 'commendation' : $level->value, 'learners' => count($learners), 'told' => $told]);

            if ($told) {
                $name = mb_strtolower(Row::text($kind, 'name'));
                $headline = $commendation ? "a commendation for {$name}" : ($level === Level::Serious ? "a serious incident ({$name}) that the school is looking into" : "an incident ({$name})");

                foreach ($learners as $learner) {
                    $this->notifier->send(new ConductRecorded($rows[$learner], $learner, $classes[$learner], $commendation ? 'commendation' : 'incident', $headline, $happenedOn), $by);
                }
            }

            return ['entry_id' => $id, 'case_id' => $case?->id];
        });
    }

    /** Withdraws an entry, with a reason: it stays in the history, and counts no more. */
    public function withdraw(User $by, string $entryId, string $reason): void
    {
        $entry = DB::table('conduct_entries')->where('id', $entryId)->first(['id', 'recorded_by']) ?? throw new NotFoundHttpException;
        $learners = array_values(array_filter(DB::table('conduct_entry_learners')->where('entry_id', $entryId)->pluck('learner_id')->all(), 'is_string'));
        $own = Row::text($entry, 'recorded_by') === $by->id;

        foreach ($learners as $learner) {
            $this->reach->learner($by, $learner, $own ? ConductPermission::Record : ConductPermission::Manage);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the entry is withdrawn.']]);
        }

        if (DB::table('conduct_cases')->where('entry_id', $entryId)->exists()) {
            throw ValidationException::withMessages(['entry' => ['This is a serious incident. Withdraw it from the incident, by someone who follows it up.']]);
        }

        DB::transaction(function () use ($by, $entryId, $reason): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['conduct:entry:'.$entryId]);

            if (DB::table('conduct_withdrawals')->where('entry_id', $entryId)->exists()) {
                throw ValidationException::withMessages(['entry' => ['The entry is already withdrawn.']]);
            }

            self::markWithdrawn($by, $entryId, $reason, $this->clock);
            $this->audit->record(ConductAudit::Withdrawn, actor: $this->audit->actorFor($by), reason: trim($reason), meta: ['entry_id' => $entryId]);
        });
    }

    public static function markWithdrawn(User $by, string $entryId, string $reason, Clock $clock): void
    {
        DB::table('conduct_withdrawals')->insertOrIgnore(['entry_id' => $entryId, 'reason' => mb_substr(trim($reason), 0, 1000), 'withdrawn_by' => $by->id, 'created_at' => $clock->now()]);
    }

    /** Gap-free, one series a year: CND-2026-000014. */
    private static function number(string $day): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A number is taken only inside the transaction that keeps the record.');
        }

        $year = (int) substr($day, 0, 4);
        DB::table('conduct_sequences')->insertOrIgnore(['year' => $year, 'next_number' => 1]);
        $row = DB::table('conduct_sequences')->where('year', $year)->lockForUpdate()->first(['next_number']);
        $n = $row === null ? 1 : Row::int($row, 'next_number');
        DB::table('conduct_sequences')->where('year', $year)->update(['next_number' => $n + 1]);

        return sprintf('CND-%d-%06d', $year, $n);
    }
}
