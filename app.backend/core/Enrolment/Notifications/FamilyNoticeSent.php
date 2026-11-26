<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Notifications;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * A notice to families that the office also sends by text message (D-115):
 * the short version the office wrote, once to each family's main contact.
 * Sent once, when the notice is published.
 */
final readonly class FamilyNoticeSent implements Topic
{
    public function __construct(
        public string $noticeId,
        public string $title,
        public string $text,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('enrolment.family_notice')->named('A notice to families')
            ->category(FamilyCategory::Families)
            ->facts('title', 'text')
            ->examples(['title' => 'School closes early on Friday', 'text' => 'School closes at 12:00 on Fri 4 Dec. Form 1-2 collected from 11:45.'])
            ->subject(':title')
            ->line(':text')
            ->short(':institution: :text')
            ->toParty(FamilyNoticeContacts::class)
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms]);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('family_notice', $this->noticeId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return ['title' => $this->title, 'text' => $this->text];
    }

    public function dedupeKey(): string
    {
        return $this->noticeId;
    }

    public function toPayload(): array
    {
        return ['notice' => $this->noticeId, 'title' => $this->title, 'text' => $this->text];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('notice'), $text('title'), $text('text'));
    }
}
