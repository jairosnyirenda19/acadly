<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Carbon\CarbonImmutable;

/**
 * Report cards and progress reviews as PDFs from the document engine
 * (D-107): the published card exactly as published, or the review as shared
 * with the family, with the school's letterhead and a check code. A class's
 * cards are joined into one PDF, one learner after another, for printing.
 */
final class CardDocuments
{
    public const string CARD = 'assessment.report_card';

    public const string REVIEW = 'assessment.progress_review';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
        private readonly Audit $audit,
    ) {}

    /** Keeps who downloaded which card or review, and when. */
    public function downloaded(User $by, string $kind, string $reference, ?string $learnerId, int $count = 1): void
    {
        $this->audit->record(AssessmentAudit::DocumentDownloaded, actor: $this->audit->actorFor($by), aboutPersonId: $learnerId,
            meta: ['kind' => $kind, 'reference' => $reference, 'count' => $count]);
    }

    /**
     * One learner's report card, or a class's cards joined.
     *
     * @param  list<array<array-key, mixed>>  $cards  each a published card with its `reference`, read defensively
     * @return array{name: string, pdf: string}
     */
    public function cards(User $viewer, array $cards, string $publishedAt, int $version, string $name): array
    {
        $instructions = array_map(fn (array $c): array => $this->card($viewer, $c, $publishedAt, $version), $cards);

        return ['name' => $name.'.pdf', 'pdf' => count($instructions) === 1 ? $this->renderer->render($instructions[0]) : $this->renderer->renderMany($instructions)];
    }

    /**
     * A progress review as the family sees it.
     *
     * @param  array{id: string, period: string, room: string, shared_at: string, areas: list<array{name: string, step: string, label: string, label_ny: string, strokes: int}>, family_note: string, home_note: string|null}  $review
     * @return array{name: string, pdf: string}
     */
    public function review(User $viewer, string $childName, string $reviewId, array $review): array
    {
        $shared = $review['shared_at'] !== '' ? CarbonImmutable::parse($review['shared_at']) : CarbonImmutable::now();
        $reference = 'PR-'.strtoupper(substr(str_replace('-', '', $reviewId), -8));
        $sections = [
            ['kind' => 'facts', 'heading' => 'Child', 'columns' => 3, 'items' => [
                ['label' => 'Child', 'value' => $childName], ['label' => 'Room', 'value' => $review['room']], ['label' => 'Term', 'value' => $review['period']],
            ]],
            ['kind' => 'table', 'heading' => $childName.' this term', 'columns' => ['Step', 'Learning area', ''], 'align' => ['mono', 'left', 'left'], 'weights' => [0.8, 3.4, 1.6],
                'rows' => array_map(fn (array $a): array => [str_repeat('/', $a['strokes']), $a['name'], $a['label']], $review['areas'])],
            ['kind' => 'text', 'text' => 'Steps: '.implode(' · ', array_values(array_unique(array_map(fn (array $a): string => str_repeat('/', $a['strokes']).' '.$a['label'], $review['areas'])))).'. No marks and no ranking.'],
            ['kind' => 'text', 'heading' => 'This term', 'style' => 'body', 'text' => $review['family_note']],
        ];
        if ($review['home_note'] !== null && trim($review['home_note']) !== '') {
            $sections[] = ['kind' => 'text', 'heading' => 'At home you can', 'style' => 'body', 'text' => $review['home_note']];
        }
        $sections[] = ['kind' => 'signatures', 'signed_by' => 'Head of nursery'];

        return ['name' => 'Progress review '.$childName.'.pdf', 'pdf' => $this->renderer->render([
            'title' => 'Progress review', 'reference' => $reference, 'status' => $review['period'].' · shared '.$shared->format('j M Y'), 'sections' => $sections,
            'check' => ['code' => $this->checks->codeFor(self::REVIEW, $reference, $shared->toDateString(), $viewer->id), 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Progress review '.$reference,
        ])];
    }

    /**
     * @param  array<array-key, mixed>  $c
     * @return array<string, mixed>
     */
    private function card(User $viewer, array $c, string $publishedAt, int $version): array
    {
        $text = fn (mixed $v): string => is_string($v) ? $v : (is_numeric($v) ? (string) $v : '');
        $arr = fn (mixed $v): array => is_array($v) ? $v : [];
        $learner = $arr($c['learner'] ?? null);
        $class = $arr($c['class'] ?? null);
        $period = $arr($c['period'] ?? null);
        $position = $arr($c['position'] ?? null);
        $total = $arr($c['total'] ?? null);
        $scale = $arr($c['scale'] ?? null);
        $reference = $text($c['reference'] ?? '');
        $published = CarbonImmutable::parse($publishedAt);
        $partOf = function (array $parts, int $i): string {
            $p = is_array($parts[$i] ?? null) ? $parts[$i] : null;
            if ($p === null || ! is_numeric($p['points'] ?? null)) {
                return '–';
            }

            return rtrim(rtrim(number_format((float) $p['points'], 1), '0'), '.').'/'.(is_numeric($p['weight'] ?? null) ? (int) $p['weight'] : 0);
        };
        $heads = ['Subject'];
        $first = $arr($arr($c['subjects'] ?? null)[0] ?? null);
        foreach ($arr($first['parts'] ?? null) as $p) {
            $p = $arr($p);
            $heads[] = $text($p['label'] ?? '').' · '.$text($p['weight'] ?? '').'%';
        }
        $partCount = count($heads) - 1;
        $rows = [];
        foreach ($arr($c['subjects'] ?? null) as $s) {
            $s = $arr($s);
            $parts = array_values($arr($s['parts'] ?? null));
            $row = [$text($s['subject'] ?? '')];
            for ($i = 0; $i < $partCount; $i++) {
                $row[] = $partOf($parts, $i);
            }
            $rows[] = [...$row, is_numeric($s['percent'] ?? null) ? rtrim(rtrim(number_format((float) $s['percent'], 1), '0'), '.').'%' : '–', $text($s['grade'] ?? '') ?: '–', $text($s['word'] ?? '')];
        }
        $comments = $arr($c['comments'] ?? null);
        $items = [];
        foreach (['class_teacher' => 'Class teacher', 'head' => 'Head teacher'] as $key => $label) {
            $cm = $arr($comments[$key] ?? null);
            if ($cm !== []) {
                $items[] = ['label' => $label.' · '.$text($cm['by'] ?? ''), 'value' => $text($cm['text'] ?? '')];
            }
        }
        $lines = array_map(fn (mixed $l): string => $text($arr($l)['label'] ?? '').': '.$text($arr($l)['value'] ?? ''), $arr($c['lines'] ?? null));
        $sections = [
            ['kind' => 'facts', 'columns' => 4, 'items' => array_values(array_filter([
                ['label' => 'Learner', 'value' => $text($learner['name'] ?? '')],
                ['label' => 'Class', 'value' => $text($class['name'] ?? '')],
                $position !== [] ? ['label' => 'Position', 'value' => $text($position['position'] ?? '').' of '.$text($position['of'] ?? '').(($position['shared'] ?? false) === true ? ' (shared)' : '')] : null,
                $total !== [] ? ['label' => 'Total', 'value' => rtrim(rtrim(number_format(is_numeric($total['total'] ?? null) ? (float) $total['total'] : 0.0, 1), '0'), '.').' of '.$text($total['out_of'] ?? '')] : null,
            ]))],
            ['kind' => 'table', 'columns' => [...$heads, 'Term', 'Grade', ''], 'align' => ['left', ...array_fill(0, $partCount, 'mono'), 'mono', 'left', 'left'],
                'weights' => [2.2, ...array_fill(0, $partCount, 1.1), 0.9, 0.7, 1.4], 'rows' => $rows],
            ['kind' => 'text', 'text' => implode(' · ', array_values(array_filter([$scale !== [] ? 'Grades: '.$text($scale['name'] ?? '') : null, ...$lines])))],
        ];
        if ($items !== []) {
            $sections[] = ['kind' => 'facts', 'heading' => 'Comments', 'columns' => 1, 'items' => $items];
        }
        if (is_string($c['next_term'] ?? null)) {
            $sections[] = ['kind' => 'text', 'text' => 'Next term begins '.CarbonImmutable::parse($c['next_term'])->format('j F Y').'.'];
        }
        $sections[] = ['kind' => 'signatures', 'signed_by' => 'Head teacher'];

        return ['title' => 'Report card', 'reference' => $reference, 'status' => $text($period['name'] ?? '').' · version '.$version, 'sections' => $sections,
            'check' => ['code' => $this->checks->codeFor(self::CARD, $reference, $published->toDateString(), $viewer->id), 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Report card '.$reference.' · published '.$published->format('j F Y')];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return rtrim(is_string($base) ? $base : '', '/').'/check';
    }
}
