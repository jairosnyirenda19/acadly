<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Sms;

/** What the school entered for its SMS provider on the Messages page. */
final readonly class SmsAccount
{
    public function __construct(
        public string $sender,
        public string $username,
        public string $key,
    ) {}
}
