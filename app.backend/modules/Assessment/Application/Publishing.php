<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\ReportCardLine;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\Approvals;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\OverallResult;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishing term results and report cards, as the screens show them (the
 * approved Publish results, Report comments, Report card and learner record
 * designs, D-080). A class is ready when every learner's term grade in every
 * subject is ready: every counted assessment locked, and every part of the
 * grade has locked work. A published version is kept as it was; "changed"
 * means the results or comments worked out now differ from it.
 *
 * @phpstan-import-type ClassRow from Reach
 * @phpstan-import-type Period from Teaching
 *
 * @phpstan-type Comment array{text: string, by: string, at: string}
 * @phpstan-type Line array{label: string, value: string}
 * @phpstan-type CardSubject array{subject_id: string, subject: string, short: string, parts: list<array{label: string, weight: int, score: float|null, points: float|null}>, percent: float|null, grade: string|null, word: string|null, passes: bool|null, ready: bool}
 * @phpstan-type Card array{learner: array{id: string, name: string, admission_number: string|null}, class: array{id: string, name: string}, period: array{id: string, name: string, starts_on: string, ends_on: string}, scale: array{name: string, overall: string, pass_from: string|null}|null, subjects: list<CardSubject>, summary: array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int}, total: array{total: float, out_of: int, complete: bool}, rank: array{position: int, of: int, shared: bool}|null, position: array{position: int, of: int, shared: bool}|null, comments: array{class_teacher: Comment|null, head: Comment|null}, lines: list<Line>, next_term: string|null}
 * @phpstan-type Current array{learners: list<array{id: string, name: string, admission_number: string|null}>, ready: bool, missing: list<string>, subjects: int, subjects_ready: int, cards: array<string, Card>, fingerprint: string}
 */
final class Publishing
{
    public const string CLASS_TEACHER = 'class_teacher';

    public const string HEAD = 'head';

    public const int COMMENT_MAX = 300;

    public function __construct(
        private readonly Reach $reach,
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $learners,
        private readonly TermGrades $grades,
        private readonly SchoolProfile $school,
        private readonly SchoolDirectory $directory,
        private readonly ModuleActivation $activation,
        private readonly Clock $clock,
        private readonly Approvals $approvals,
        private readonly Requests $requests,
    ) {}

    /** @return array{show_position: bool, head_comment: bool} what report cards show; both on until the school says otherwise */
    public function settings(): array
    {
        $row = DB::table('result_card_settings')->orderByDesc('seq')->first(['show_position', 'head_comment']);

        return ['show_position' => $row === null || (bool) $row->show_position, 'head_comment' => $row === null || (bool) $row->head_comment];
    }

    /**
     * The classes the viewer may publish, each with its term, how ready it is and its latest version.
     *
     * @return array<string, mixed>
     */
    public function screen(User $viewer): array
    {
        $rows = [];

        foreach ($this->reach->classes($viewer) as $class) {
            if (! $this->reach->may($viewer, AssessmentPermission::Publish, $class['id'])) {
                continue;
            }

            $period = $this->teaching->periodAt($class['division_id'], $this->clock->today());

            if ($period === null) {
                continue;
            }

            $now = $this->current($class, $period);
            $latest = $this->latest($class['id'], $period['id']);
            $changed = $latest !== null && $latest['fingerprint'] !== $now['fingerprint'];
            $waiting = $this->requests->publish($class['id'], $period['id']);
            $comments = count(array_filter($now['cards'], fn (array $c): bool => $c['comments'][self::CLASS_TEACHER] !== null));

            $rows[] = [
                'class_id' => $class['id'],
                'class' => $class['name'],
                'level' => $this->reach->levelLabel($class['division_id'], $class['level_code']),
                'campus' => $this->directory->campus($class['campus_id'])->name ?? '',
                'period' => ['id' => $period['id'], 'name' => $period['name']],
                'learners' => count($now['learners']),
                'subjects' => $now['subjects'],
                'subjects_ready' => $now['subjects_ready'],
                'comments' => $comments,
                'ready' => $now['ready'],
                'missing' => $now['missing'],
                'state' => $waiting !== null ? 'waiting' : ($latest === null ? ($now['ready'] ? 'ready' : 'not_ready') : ($changed ? 'changed' : 'published')),
                'waiting' => $waiting !== null ? [...$waiting['pending'], 'changed' => $waiting['fingerprint'] !== $now['fingerprint']] : null,
                'latest' => $latest !== null ? ['id' => $latest['id'], 'version' => $latest['version'], 'published_at' => $latest['published_at']] : null,
            ];
        }

        return ['classes' => $rows, 'settings' => $this->settings(), 'asks' => $this->approvals->needed(PublishApproval::KEY)];
    }

    /**
     * The report comments of a class this term (the approved phone screen), one row per learner.
     *
     * @return array<string, mixed>
     */
    public function commentsScreen(User $viewer, string $classId): array
    {
        [$class, $period] = $this->place($viewer, $classId);
        $now = $this->current($class, $period);
        $rows = [];

        foreach ($now['learners'] as $learner) {
            $card = $now['cards'][$learner['id']];
            $rows[] = [
                'id' => $learner['id'],
                'name' => $learner['name'],
                'total' => $card['total'],
                'position' => $card['rank'],
                'grades' => array_map(fn (array $s): array => ['subject' => $s['subject'], 'short' => $s['short'], 'grade' => $s['grade'], 'ready' => $s['ready']], $card['subjects']),
                'lines' => $card['lines'],
                'comments' => $card['comments'],
            ];
        }

        $settings = $this->settings();

        return [
            'class' => ['id' => $class['id'], 'name' => $class['name']],
            'period' => ['id' => $period['id'], 'name' => $period['name']],
            'can_class' => $this->writes($viewer, $class['id'], self::CLASS_TEACHER),
            'can_head' => $settings['head_comment'] && $this->writes($viewer, $class['id'], self::HEAD),
            'head_comment' => $settings['head_comment'],
            'max' => self::COMMENT_MAX,
            'learners' => $rows,
            'written' => count(array_filter($rows, fn (array $r): bool => $r['comments'][self::CLASS_TEACHER] !== null)),
        ];
    }

    /** Who writes which comment: the class teacher's by the class teacher or a publisher; the head teacher's by a publisher. */
    public function writes(User $who, string $classId, string $author): bool
    {
        $publisher = $this->reach->may($who, AssessmentPermission::Publish, $classId);

        return match ($author) {
            self::CLASS_TEACHER => $publisher || $this->calendar->classTeacherId($classId) === $who->id,
            self::HEAD => $publisher,
            default => false,
        };
    }

    /**
     * Published report cards: every learner of the version, or one.
     *
     * @return array<string, mixed>
     */
    public function cards(User $viewer, string $publicationId, ?string $learnerId): array
    {
        $pub = Ids::isValid($publicationId) ? DB::table('result_publications')->where('id', $publicationId)->first() : null;

        if ($pub === null || ! $this->reach->sees($viewer, Row::text($pub, 'class_id'))) {
            throw new NotFoundHttpException;
        }

        $query = DB::table('result_publication_learners')->where('publication_id', $publicationId)->orderBy('id');

        if ($learnerId !== null) {
            $query->where('learner_id', Ids::isValid($learnerId) ? $learnerId : Ids::next());
        }

        $cards = array_values(array_map(fn (object $r): array => [...self::decode(Row::text($r, 'card')), 'reference' => Row::text($r, 'reference')], $query->get(['card', 'reference'])->all()));

        if ($learnerId !== null && $cards === []) {
            throw new NotFoundHttpException;
        }

        usort($cards, fn (array $a, array $b): int => self::nameOf($a) <=> self::nameOf($b));

        return [
            'school' => ['name' => $this->school->name(), 'has_logo' => $this->school->hasLogo()],
            'publication' => self::publicationOut($pub),
            'cards' => $cards,
        ];
    }

    /**
     * What was published of a learner this term: the version in force and every version.
     *
     * @return array<string, mixed>|null
     */
    public function forLearner(string $learnerId, string $classId, string $periodId): ?array
    {
        $rows = DB::table('result_publications as p')->join('result_publication_learners as l', 'l.publication_id', '=', 'p.id')
            ->where('p.class_id', $classId)->where('p.period_id', $periodId)->where('l.learner_id', $learnerId)
            ->orderByDesc('p.version')->get(['p.id', 'p.version', 'p.reason', 'p.published_by', 'p.published_at', 'l.card', 'l.reference'])->all();

        if ($rows === []) {
            return null;
        }

        $names = $this->staffNames(array_map(fn (object $r): string => Row::text($r, 'published_by'), $rows));
        $first = $rows[0];
        $card = self::decode(Row::text($first, 'card'));

        return [
            'publication_id' => Row::text($first, 'id'),
            'version' => Row::int($first, 'version'),
            'published_at' => Row::text($first, 'published_at'),
            'reference' => Row::text($first, 'reference'),
            'subjects' => $card['subjects'] ?? [],
            'total' => $card['total'] ?? null,
            'position' => $card['position'] ?? null,
            'versions' => array_map(fn (object $r): array => [
                'publication_id' => Row::text($r, 'id'),
                'version' => Row::int($r, 'version'),
                'published_at' => Row::text($r, 'published_at'),
                'by' => $names[Row::text($r, 'published_by')] ?? '',
                'reason' => Row::maybeText($r, 'reason'),
            ], $rows),
        ];
    }

    /**
     * The class's results worked out now, as report cards: what publishing would keep.
     *
     * @param  ClassRow  $class
     * @param  Period  $period
     * @return Current
     */
    public function current(array $class, array $period): array
    {
        $today = $this->clock->today();
        $learners = $this->learners->learnersIn($class['id'], min($today, $period['ends_on']));
        $ids = array_column($learners, 'id');
        $result = $this->grades->forClass($class, $period['id'], $ids);
        $scale = $result['scale'];
        $settings = $this->settings();
        $comments = $this->comments($class['id'], $period['id']);
        $lines = $this->lines($class, $ids, $period['starts_on'], min($today, $period['ends_on']));
        $next = $this->nextTerm($class['division_id'], $period);
        $missing = [];
        $subjectsReady = 0;
        $totals = [];

        foreach ($result['subjects'] as $subject) {
            $waiting = null;

            foreach ($result['grades'][$subject['id']] ?? [] as $w) {
                $waiting ??= $w['not_ready'];
            }

            if ($waiting === null && $ids !== []) {
                $subjectsReady++;
            } elseif ($waiting !== null) {
                $missing[] = $subject['name'].': '.$waiting;
            }
        }

        if ($ids === []) {
            $missing[] = 'No learners are in the class.';
        }

        if ($result['subjects'] === []) {
            $missing[] = 'No subjects are taught in the class.';
        }

        foreach ($ids as $id) {
            $totals[$id] = TermGrades::total(array_map(fn (array $bySubject): array => $bySubject[$id], $result['grades']));
        }

        $positions = TermGrades::positions($totals);
        $cards = [];

        foreach ($learners as $learner) {
            $subjects = [];
            $bySubject = [];

            foreach ($result['subjects'] as $subject) {
                $w = $result['grades'][$subject['id']][$learner['id']];
                $bySubject[$subject['id']] = $w;
                $subjects[] = [
                    'subject_id' => $subject['id'], 'subject' => $subject['name'], 'short' => $subject['short'],
                    'parts' => array_map(fn (array $p): array => ['label' => $p['label'], 'weight' => $p['weight'], 'score' => $p['score'],
                        'points' => $p['score'] !== null ? round($p['score'] * $p['weight'] / 100, 1) : null], $w['parts']),
                    'percent' => $w['percent'], 'grade' => $w['grade'], 'word' => $w['word'], 'passes' => $w['passes'], 'ready' => $w['ready'],
                ];
            }

            $said = $comments[$learner['id']] ?? [];
            $cards[$learner['id']] = [
                'learner' => ['id' => $learner['id'], 'name' => $learner['name'], 'admission_number' => $learner['admission_number']],
                'class' => ['id' => $class['id'], 'name' => $class['name']],
                'period' => ['id' => $period['id'], 'name' => $period['name'], 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']],
                'scale' => $scale !== null ? ['name' => $scale['name'], 'overall' => $scale['overall']->value, 'pass_from' => $scale['pass_from']] : null,
                'subjects' => $subjects,
                'summary' => TermGrades::summary($scale, $bySubject),
                'total' => $totals[$learner['id']],
                'rank' => $positions[$learner['id']] ?? null,
                'position' => $settings['show_position'] ? ($positions[$learner['id']] ?? null) : null,
                'comments' => [
                    self::CLASS_TEACHER => $said[self::CLASS_TEACHER] ?? null,
                    self::HEAD => $settings['head_comment'] ? ($said[self::HEAD] ?? null) : null,
                ],
                'lines' => array_values(array_filter(array_map(fn (array $byLearner): ?array => $byLearner[$learner['id']] ?? null, $lines))),
                'next_term' => $next,
            ];
        }

        return [
            'learners' => $learners,
            'ready' => $missing === [],
            'missing' => $missing,
            'subjects' => count($result['subjects']),
            'subjects_ready' => $subjectsReady,
            'cards' => $cards,
            'fingerprint' => self::fingerprint($cards, ($scale['overall'] ?? OverallResult::AveragePercent)->value),
        ];
    }

    /**
     * The latest published version of a class's term.
     *
     * @return array{id: string, version: int, published_at: string, fingerprint: string}|null
     */
    public function latest(string $classId, string $periodId): ?array
    {
        $row = DB::table('result_publications')->where('class_id', $classId)->where('period_id', $periodId)->orderByDesc('version')
            ->first(['id', 'version', 'published_at', 'fingerprint']);

        return $row === null ? null : ['id' => Row::text($row, 'id'), 'version' => Row::int($row, 'version'), 'published_at' => Row::text($row, 'published_at'), 'fingerprint' => Row::text($row, 'fingerprint')];
    }

    /**
     * The class and its current term, when the viewer sees the class or teaches it as class teacher.
     *
     * @return array{0: ClassRow, 1: Period}
     */
    public function place(User $viewer, string $classId): array
    {
        $class = Ids::isValid($classId) ? $this->calendar->classInForce($classId) : null;

        if ($class === null || ! $this->reach->takesMarks($class['division_id'])
            || (! $this->reach->sees($viewer, $class['id']) && $this->calendar->classTeacherId($class['id']) !== $viewer->id)) {
            throw new NotFoundHttpException;
        }

        $period = $this->teaching->periodAt($class['division_id'], $this->clock->today());

        if ($period === null) {
            throw new NotFoundHttpException;
        }

        return [$class, $period];
    }

    /**
     * The comment in force of each learner and author: the latest row. An empty text is no comment.
     *
     * @return array<string, array<string, Comment>>
     */
    private function comments(string $classId, string $periodId): array
    {
        $rows = DB::table('result_comments')->where('class_id', $classId)->where('period_id', $periodId)->orderBy('seq')
            ->get(['learner_id', 'author', 'text', 'written_by', 'written_at'])->all();
        $names = $this->staffNames(array_map(fn (object $r): string => Row::text($r, 'written_by'), $rows));
        $out = [];

        foreach ($rows as $r) {
            $out[Row::text($r, 'learner_id')][Row::text($r, 'author')] = ['text' => Row::text($r, 'text'), 'by' => $names[Row::text($r, 'written_by')] ?? '', 'at' => Row::text($r, 'written_at')];
        }

        foreach ($out as $learner => $byAuthor) {
            $out[$learner] = array_filter($byAuthor, fn (array $c): bool => $c['text'] !== '');
        }

        return $out;
    }

    /**
     * Every module's line on the cards, while the module is on for the class.
     *
     * @param  ClassRow  $class
     * @param  list<string>  $ids
     * @return list<array<string, array{label: string, value: string}>>
     */
    private function lines(array $class, array $ids, string $from, string $to): array
    {
        $at = ScopeRef::of(ScopeType::ClassGroup, $class['id']);
        $out = [];

        foreach (app()->tagged(ReportCardLine::TAG) as $line) {
            if ($line instanceof ReportCardLine && $this->activation->isActive($line->key(), $at)) {
                $out[] = $line->lines($ids, $from, $to);
            }
        }

        return $out;
    }

    /** @param Period $period the day the next term of the level begins, this school year */
    private function nextTerm(string $divisionId, array $period): ?string
    {
        foreach ($this->calendar->currentYear($period['starts_on'])['terms'][$divisionId] ?? [] as $term) {
            if ($term['starts_on'] > $period['ends_on']) {
                return $term['starts_on'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function staffNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (User::query()->whereIn('id', $ids)->get(['id', 'name']) as $user) {
            $out[$user->id] = $user->name;
        }

        return $out;
    }

    /**
     * What decides whether a published version still says what is true now:
     * every grade and comment of every learner.
     *
     * @param  array<string, array<string, mixed>>  $cards
     */
    private static function fingerprint(array $cards, string $overall): string
    {
        $facts = [$overall];

        foreach ($cards as $id => $card) {
            $subjects = is_array($card['subjects'] ?? null) ? $card['subjects'] : [];
            $comments = is_array($card['comments'] ?? null) ? $card['comments'] : [];
            $facts[$id] = [
                array_map(fn (mixed $s): array => is_array($s) ? [$s['subject_id'] ?? null, $s['percent'] ?? null, $s['grade'] ?? null] : [], $subjects),
                array_map(fn (mixed $c): mixed => is_array($c) ? ($c['text'] ?? null) : null, $comments),
            ];
        }

        ksort($facts);

        return hash('sha256', (string) json_encode($facts));
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $value = json_decode($json, true);
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $card */
    private static function nameOf(array $card): string
    {
        $learner = $card['learner'] ?? null;

        return is_array($learner) && is_string($learner['name'] ?? null) ? $learner['name'] : '';
    }

    /** @return array{id: string, class_id: string, period_id: string, version: int, published_at: string} */
    private static function publicationOut(object $pub): array
    {
        return ['id' => Row::text($pub, 'id'), 'class_id' => Row::text($pub, 'class_id'), 'period_id' => Row::text($pub, 'period_id'),
            'version' => Row::int($pub, 'version'), 'published_at' => Row::text($pub, 'published_at')];
    }
}
