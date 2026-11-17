<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Publishing;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Domain\Publication;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Acadly\Modules\Assessment\Notifications\ResultsPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishes a class's term results (D-080): keeps every learner's report card
 * as it is now, as the next version, and tells each family. Only when the
 * class is ready, only by those who may publish there, and never by a person
 * who submitted marks that count in these results. A version is never
 * changed; a correction is published as the next one, which says what changed.
 */
final class PublishResults
{
    public function __construct(
        private readonly Access $access,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly Reach $reach,
        private readonly Publishing $publishing,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<string>  $classIds
     * @return list<array{class_id: string, publication_id: string, version: int}>
     */
    public function handle(User $by, array $classIds): array
    {
        $classIds = array_values(array_unique(array_filter($classIds, fn (string $id): bool => Ids::isValid($id))));

        if ($classIds === []) {
            throw ValidationException::withMessages(['class_ids' => ['Choose the classes to publish.']]);
        }

        $out = [];

        foreach ($classIds as $classId) {
            $out[] = $this->one($by, $classId);
        }

        return $out;
    }

    /** @return array{class_id: string, publication_id: string, version: int} */
    private function one(User $by, string $classId): array
    {
        $class = $this->calendar->classInForce($classId);

        if ($class === null || ! $this->reach->takesMarks($class['division_id']) || ! $this->reach->sees($by, $class['id'])) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);
        $this->access->check($by, AssessmentPermission::Publish, $at);
        $period = $this->teaching->periodAt($class['division_id'], $this->clock->today()) ?? throw new NotFoundHttpException;

        if ($this->submittedHere($by, $class['id'], $period['id'])) {
            throw Problem::anotherPersonRequired();
        }

        return DB::transaction(function () use ($by, $class, $period, $at): array {
            // One publisher at a time for a class and term.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['result_publications:'.$class['id'].':'.$period['id']]);
            $now = $this->publishing->current($class, $period);

            if (! $now['ready']) {
                throw ValidationException::withMessages(['class_ids' => [$class['name'].' is not ready: '.($now['missing'][0] ?? 'some results are missing.')]]);
            }

            $latest = $this->publishing->latest($class['id'], $period['id']);

            if ($latest !== null && $latest['fingerprint'] === $now['fingerprint']) {
                throw ValidationException::withMessages(['class_ids' => [$class['name'].' is published already, and nothing has changed since.']]);
            }

            $settings = $this->publishing->settings();
            $publication = Publication::query()->create([
                'class_id' => $class['id'], 'period_id' => $period['id'], 'version' => ($latest['version'] ?? 0) + 1,
                'reason' => $latest !== null ? $this->whatChanged($latest['id'], $now['cards']) : null,
                'fingerprint' => $now['fingerprint'], 'settings' => $settings, 'published_by' => $by->id, 'published_at' => $this->clock->now(),
            ]);

            foreach ($now['cards'] as $learnerId => $card) {
                unset($card['rank']);
                DB::table('result_publication_learners')->insert(['id' => Ids::next(), 'publication_id' => $publication->id, 'learner_id' => $learnerId,
                    'reference' => self::reference($publication->version), 'card' => json_encode($card)]);
            }

            $this->audit->record(AssessmentAudit::ResultsPublished, subject: $publication, actor: $this->audit->actorFor($by), at: $at,
                meta: ['class' => $class['id'], 'period' => $period['id'], 'version' => $publication->version, 'learners' => count($now['cards'])]);

            foreach (array_keys($now['cards']) as $learnerId) {
                $this->notifier->send(new ResultsPublished($publication->id, $learnerId, $class['id'], $period['name']), $by);
            }

            return ['class_id' => $class['id'], 'publication_id' => $publication->id, 'version' => $publication->version];
        });
    }

    /** Whether the person submitted marks of an assessment of the class this term: then someone else publishes. */
    private function submittedHere(User $by, string $classId, string $periodId): bool
    {
        $ids = DB::table('assessments')->where('class_id', $classId)->where('period_id', $periodId)->where('counts', true)->pluck('id')->all();

        return $ids !== [] && DB::table('journey_step_completions')->where('subject_type', 'assessment')->whereIn('subject_id', $ids)
            ->where('step', StepId::Submit->value)->where('actor_user_id', $by->id)->exists();
    }

    /**
     * The subjects whose grades changed since the version before, with how many learners: "Changed: Biology (2 learners)."
     *
     * @param  array<string, array<string, mixed>>  $cards
     */
    private function whatChanged(string $previousId, array $cards): string
    {
        $before = [];

        foreach (DB::table('result_publication_learners')->where('publication_id', $previousId)->get(['learner_id', 'card']) as $row) {
            $before[Row::text($row, 'learner_id')] = Publishing::decode(Row::text($row, 'card'));
        }

        $counts = [];
        $comments = 0;

        foreach ($cards as $learnerId => $card) {
            $old = $before[$learnerId] ?? [];
            $was = [];

            foreach (is_array($old['subjects'] ?? null) ? $old['subjects'] : [] as $s) {
                if (is_array($s) && is_string($s['subject'] ?? null)) {
                    $was[$s['subject']] = [$s['percent'] ?? null, $s['grade'] ?? null];
                }
            }

            foreach (is_array($card['subjects'] ?? null) ? $card['subjects'] : [] as $s) {
                if (is_array($s) && is_string($s['subject'] ?? null) && ($was[$s['subject']] ?? null) != [$s['percent'] ?? null, $s['grade'] ?? null]) {
                    $counts[$s['subject']] = ($counts[$s['subject']] ?? 0) + 1;
                }
            }

            $comments += ($old['comments'] ?? null) != ($card['comments'] ?? null) ? 1 : 0;
        }

        $parts = array_map(fn (string $subject, int $n): string => $subject.' ('.$n.' learner'.($n === 1 ? '' : 's').')', array_keys($counts), $counts);

        if ($comments > 0) {
            $parts[] = 'comments ('.$comments.' learner'.($comments === 1 ? '' : 's').')';
        }

        return mb_substr($parts === [] ? 'Learners in the class changed.' : 'Changed: '.implode(', ', $parts).'.', 0, 255);
    }

    /** A reference printed on the card, to check it against the record: R2-7K4Q-29XD. */
    private static function reference(int $version): string
    {
        $part = fn (): string => strtoupper(Str::random(4));

        return 'R'.$version.'-'.$part().'-'.$part();
    }
}
