<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Channels;

/** The words of one delivery, with every token filled. */
final readonly class RenderedNotice
{
    public function __construct(
        public string $deliveryId,
        public string $topicKey,
        public string $subject,
        public string $body,
        public ?string $short,
        public ?string $actionUrl,
    ) {}
}
