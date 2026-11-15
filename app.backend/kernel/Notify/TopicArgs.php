<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Blueprint\Journey\EffectArgs;

/** The topic a `SendTopic` effect sends, by class name. */
final readonly class TopicArgs implements EffectArgs
{
    public function __construct(public string $topic) {}

    public function toArray(): array
    {
        return ['topic' => $this->topic];
    }

    public static function fromArray(array $values): static
    {
        $topic = $values['topic'] ?? '';

        return new self(is_string($topic) ? $topic : '');
    }
}
