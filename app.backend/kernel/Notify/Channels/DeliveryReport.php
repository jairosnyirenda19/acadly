<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

/** What a provider later reported about one message: delivered to the phone, or not. */
final readonly class DeliveryReport
{
    public function __construct(
        public string $providerRef,
        public bool $delivered,
        public ?string $reason = null,
    ) {}
}
