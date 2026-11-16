<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Contracts;

use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Kernel\Support\Phone;

/**
 * The party of a notice about a learner: the contacts in force when the
 * notice is sent, each by the channel they chose. The school decides on the
 * Messages page whether only the main contact is told, or every contact.
 */
final class LearnerContacts implements PartyResolver
{
    public function __construct(
        private readonly EnrolmentDirectory $directory,
        private readonly MessageSettings $settings,
    ) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof AboutLearner) {
            return [];
        }

        $contacts = $this->directory->contactsOf($topic->learnerId());

        if ($this->settings->contacts($topic::notice()->key) === 'main') {
            $contacts = array_slice($contacts, 0, 1);
        }

        return array_map(fn (array $c): Recipient => self::recipient($c), $contacts);
    }

    /** @param  array{id: string, name: string, relationship: string, phone: string, email: string|null, notices_by: string, main: bool}  $contact */
    public static function recipient(array $contact): Recipient
    {
        return new Recipient($contact['name'], null, $contact['email'], Phone::international($contact['phone']),
            $contact['notices_by'] === 'email' ? Channel::Email : Channel::Sms);
    }
}
