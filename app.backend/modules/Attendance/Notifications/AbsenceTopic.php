<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Carbon\CarbonImmutable;

/**
 * A learner marked absent on a register, told to the family. The record is
 * the first mark, so every notice about one absence lists together.
 */
abstract readonly class AbsenceTopic implements AboutLearner, Topic
{
    public const string SUBJECT = 'attendance.absence';

    final public function __construct(
        public string $markId,
        public string $learner,
        public string $classId,
        public string $day,
        public int $round = 0,
    ) {}

    /** The words both notices use: the declared English. */
    protected static function words(Notice $notice): Notice
    {
        return $notice
            ->category(AttendanceCategory::Attendance)
            ->facts('learner', 'class', 'day')
            ->examples(['learner' => 'Kumbukani Jere', 'class' => 'Form 1 East', 'day' => 'Tue 17 Nov'])
            ->subject(':learner was marked absent')
            ->line(':learner was marked absent from :class on :day.')
            ->line('If you did not know, please call the school on :school_phone.')
            ->short(':institution: :learner was marked absent from :class on :day. If you did not know, please call the school on :school_phone.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email]);
    }

    public function learnerId(): string
    {
        return $this->learner;
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef(self::SUBJECT, $this->markId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::of(ScopeType::ClassGroup, $this->classId);
    }

    public function tokens(): array
    {
        return [
            'learner' => app(EnrolmentDirectory::class)->names([$this->learner])[$this->learner] ?? null,
            'class' => app(AcademicCalendar::class)->classNames([$this->classId])[$this->classId]['name'] ?? null,
            'day' => CarbonImmutable::parse($this->day)->format('D j M'),
        ];
    }

    public function dedupeKey(): string
    {
        return $this->markId.':'.$this->round;
    }

    public function toPayload(): array
    {
        return ['mark' => $this->markId, 'learner' => $this->learner, 'class' => $this->classId, 'day' => $this->day, 'round' => $this->round];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $round = $payload['round'] ?? 0;

        return new static($text('mark'), $text('learner'), $text('class'), $text('day'), is_int($round) ? $round : 0);
    }
}
