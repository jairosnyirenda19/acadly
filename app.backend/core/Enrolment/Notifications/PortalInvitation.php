<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Notifications;

use Acadly\Core\Enrolment\Application\FamilyPhone;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\Notice;
use Acadly\Kernel\Notify\SubjectRef;
use Acadly\Kernel\Notify\Topic;

/**
 * The office invites a family to the parent portal (D-083): one text
 * message to one phone number, ending with the portal's address. It names
 * no child, so a number kept by mistake learns nothing.
 */
final readonly class PortalInvitation implements Topic
{
    public function __construct(
        public string $invitationId,
        public string $phone,
    ) {}

    public static function notice(): Notice
    {
        return Notice::make('enrolment.portal_invitation')->named('Parent portal invitation')
            ->category(FamilyCategory::Families)
            ->subject('The parent portal of :institution')
            ->line('You can now see your children\'s attendance and report cards on your phone.')
            ->line('Sign in with this phone number. A code comes by text message; there is no password.')
            ->short(':institution: see your children\'s attendance and report cards on your phone. No password is needed.')
            ->portalLink()
            ->toParty(FamilyPhone::class)
            ->channels(default: [Channel::Sms], allowed: [Channel::Sms]);
    }

    public function subject(): SubjectRef
    {
        return new SubjectRef('portal_invitation', $this->invitationId);
    }

    public function scope(): ScopeRef
    {
        return ScopeRef::institution();
    }

    public function tokens(): array
    {
        return [];
    }

    public function dedupeKey(): string
    {
        return $this->invitationId;
    }

    public function toPayload(): array
    {
        return ['invitation' => $this->invitationId, 'phone' => $this->phone];
    }

    public static function fromPayload(array $payload): static
    {
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';

        return new self($text('invitation'), $text('phone'));
    }
}
