<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Notifications\PortalInvitation;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;

/**
 * The party of a portal invitation: the phone number it names, while it is
 * still a contact in force of a learner at the school. Resolved at send
 * time, so a number removed meanwhile receives nothing.
 */
final class FamilyPhone implements PartyResolver
{
    public function __construct(private readonly Families $families) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof PortalInvitation) {
            return [];
        }

        $name = $this->families->nameFor($topic->phone);

        return $name !== null ? [new Recipient($name, null, null, $topic->phone, Channel::Sms)] : [];
    }
}
