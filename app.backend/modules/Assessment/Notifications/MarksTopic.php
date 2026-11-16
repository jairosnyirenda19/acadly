<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Blueprint\Journey\JourneyContext;
use Acadly\Kernel\Notify\JourneyTopic;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Modules\Assessment\Domain\Assessment;
use Illuminate\Support\Facades\DB;

/**
 * A step of `assessment.marks` worth telling staff about. The record is the
 * assessment; the round is the journey's step number, so a second submission
 * after a return is a new event.
 */
abstract readonly class MarksTopic implements JourneyTopic
{
    final public function __construct(
        public string $assessmentId,
        public string $classId,
        public string $subjectId,
        public string $title,
        public string $round,
    ) {}

    public static function fromJourney(JourneyContext $context): static
    {
        $a = $context->subject;
        $seq = DB::table('journey_step_completions')->where('subject_type', $a->auditType())->where('subject_id', $a->auditId())->max('seq');
        $round = $context->step.':'.((is_numeric($seq) ? (int) $seq : 0) + 1);

        return $a instanceof Assessment
            ? new static($a->id, $a->class_id, $a->subject_id, $a->title, $round)
            : new static($a->auditId(), '', '', '', $round);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('assessment', $this->assessmentId);
    }

    public function scope(): ScopeRef
    {
        return $this->classId !== '' ? ScopeRef::of(ScopeType::ClassGroup, $this->classId) : ScopeRef::institution();
    }

    public function tokens(): array
    {
        return [
            'assessment' => $this->assessmentId,
            'title' => $this->title,
            'class' => app(AcademicCalendar::class)->classNames([$this->classId])[$this->classId]['name'] ?? null,
            'subject' => app(Teaching::class)->subjects([$this->subjectId])[$this->subjectId]['name'] ?? null,
        ];
    }

    public function dedupeKey(): string
    {
        return $this->assessmentId.':'.$this->round;
    }

    public function toPayload(): array
    {
        return ['assessment' => $this->assessmentId, 'class' => $this->classId, 'subject' => $this->subjectId, 'title' => $this->title, 'round' => $this->round];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new static($text('assessment'), $text('class'), $text('subject'), $text('title'), $text('round'));
    }
}
