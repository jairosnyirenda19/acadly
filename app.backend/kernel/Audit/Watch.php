<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of a record taken before an Action changes it. The Action asks
 * it for the diff after the change. No state is kept anywhere else.
 */
final readonly class Watch
{
    /** @param  array<string, mixed>  $before */
    private function __construct(
        public Auditable $subject,
        private array $before,
    ) {}

    public static function of(Auditable $subject): self
    {
        return new self($subject, self::attributes($subject));
    }

    /** A new record: every declared field is compared with nothing. */
    public static function created(Auditable $subject): self
    {
        return new self($subject, []);
    }

    public function diff(): Diff
    {
        return Diff::between($this->before, self::attributes($this->subject), $this->subject->auditFields());
    }

    /** @return array<string, mixed> */
    private static function attributes(Auditable $subject): array
    {
        if (! $subject instanceof Model) {
            return [];
        }

        $out = [];

        foreach (array_keys($subject->auditFields()) as $field) {
            $out[$field] = $subject->getAttribute($field);
        }

        return $out;
    }
}
