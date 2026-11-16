<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Contracts\AboutLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;
use Carbon\CarbonImmutable;

/**
 * A learner here this morning and marked absent from a lesson later (the
 * approved Missed lessons design). Off until the school switches it on. Sent
 * when the lesson register for today is submitted, once a lesson, to the main
 * contact (or every contact, as the school chose), by the channel each chose.
 */
final readonly class LessonMissed implements AboutLearner, Topic
{
    public const string SUBJECT = 'attendance.lesson_mark';

    public function __construct(
        public string $markId,
        public string $learner,
        public string $classId,
        public string $day,
        public string $subjectName,
        public string $starts,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('attendance.lesson_missed')->named('Missed a lesson')
            ->category(AttendanceCategory::Attendance)
            ->facts('learner', 'class', 'subject', 'time', 'day')
            ->examples(['learner' => 'Chimwemwe Chirwa', 'class' => 'Form 2 West', 'subject' => 'Biology', 'time' => '10:15', 'day' => 'Tue 17 Nov'])
            ->subject(':learner missed a lesson')
            ->line(':learner was at school this morning but was absent from :subject at :time on :day.')
            ->line('If you did not know, please call the school on :school_phone.')
            ->short(':institution: :learner was at school this morning but was absent from :subject at :time (:day). Please call :school_phone.')
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms, Channel::Email])
            ->toParty(LearnerContacts::class)
            ->startsOff();
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
            'subject' => $this->subjectName,
            'time' => $this->starts,
            'day' => CarbonImmutable::parse($this->day)->format('D j M'),
        ];
    }

    public function dedupeKey(): string
    {
        return $this->markId;
    }

    public function toPayload(): array
    {
        return ['mark' => $this->markId, 'learner' => $this->learner, 'class' => $this->classId, 'day' => $this->day, 'subject' => $this->subjectName, 'starts' => $this->starts];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('mark'), $text('learner'), $text('class'), $text('day'), $text('subject'), $text('starts'));
    }
}
