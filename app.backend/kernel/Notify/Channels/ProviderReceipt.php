<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

use Acadly\Kernel\Notify\DeliveryStatus;

/** What a channel or provider answered for one send. */
final readonly class ProviderReceipt
{
    private function __construct(
        public DeliveryStatus $status,
        public ?string $providerRef,
        public ?string $reason,
        public int $units,
        public ?string $provider = null,
    ) {}

    /** The same answer, naming the provider that gave it. */
    public function from(string $provider): self
    {
        return new self($this->status, $this->providerRef, $this->reason, $this->units, $provider);
    }

    public static function sent(?string $providerRef = null, int $units = 1): self
    {
        return new self(DeliveryStatus::Sent, $providerRef, null, $units);
    }

    public static function delivered(?string $providerRef = null): self
    {
        return new self(DeliveryStatus::Delivered, $providerRef, null, 1);
    }

    public static function failed(string $reason): self
    {
        return new self(DeliveryStatus::Failed, null, $reason, 0);
    }
}
