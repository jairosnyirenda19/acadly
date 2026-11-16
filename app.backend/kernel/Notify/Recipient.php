<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Identity\User;

/**
 * A person a notice goes to: with an account, or only an address. A family
 * member with only a phone number is still a recipient (04 §4).
 */
final readonly class Recipient
{
    public function __construct(
        public string $name,
        public ?string $userId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?Channel $prefers = null,
    ) {}

    public static function user(User $user): self
    {
        return new self($user->name, $user->id, $user->email);
    }

    /** One key per person, so nobody is told twice for one event. */
    public function key(): string
    {
        return match (true) {
            $this->userId !== null => 'user:'.$this->userId,
            $this->phone !== null => 'phone:'.$this->phone,
            $this->email !== null => 'email:'.mb_strtolower($this->email),
            default => 'name:'.$this->name,
        };
    }

    /** Where this person can be reached on a channel, or null. */
    public function address(Channel $channel): ?string
    {
        return match ($channel) {
            Channel::InApp => $this->userId,
            Channel::Email => $this->email,
            Channel::Sms => $this->phone,
        };
    }
}
