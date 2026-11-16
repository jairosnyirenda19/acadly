<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance\Notifications;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerContacts;
use Acadly\Kernel\Notify\Deliveries;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Topic;

/** The first contact of the learner the school has not yet tried for this absence, or nobody when all were tried. */
final class NextContact implements PartyResolver
{
    public function __construct(
        private readonly EnrolmentDirectory $directory,
        private readonly Deliveries $deliveries,
    ) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof AbsenceTopic) {
            return [];
        }

        $told = self::told($this->deliveries->about(AbsenceTopic::SUBJECT, [$topic->markId])[$topic->markId] ?? []);

        foreach ($this->directory->contactsOf($topic->learner) as $contact) {
            $recipient = LearnerContacts::recipient($contact);

            if (! in_array($recipient->phone, $told, true) && ! in_array($recipient->email, $told, true)) {
                return [$recipient];
            }
        }

        return [];
    }

    /**
     * The addresses already tried for this absence, reached or not: the next
     * contact is someone the school has not tried yet.
     *
     * @param  list<array{phone: string|null, email: string|null, state: string}>  $deliveries
     * @return list<string>
     */
    public static function told(array $deliveries): array
    {
        $out = [];

        foreach ($deliveries as $d) {
            $out = [...$out, ...array_filter([$d['phone'], $d['email']], 'is_string')];
        }

        return $out;
    }
}
