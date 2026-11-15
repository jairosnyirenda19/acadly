<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

/** The record a notice is about, by its audit type and id. The kernel knows no more than that. */
final readonly class SubjectRef
{
    public function __construct(
        public string $type,
        public ?string $id,
    ) {}

    public static function none(): self
    {
        return new self('none', null);
    }
}
