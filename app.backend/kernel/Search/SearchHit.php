<?php

declare(strict_types=1);

namespace Acadly\Kernel\Search;

/**
 * One thing found (12-search §1). Built field by field, so a column added to
 * a table can never appear in search by accident. `kind` and `id` say what
 * opens: the page does its own access check.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public ?string $reference = null,
        public ?string $subtitle = null,
        public ?string $state = null,
        public ?string $parent = null,
    ) {}

    /** @return array{id: string, kind: string, title: string, reference: string|null, subtitle: string|null, state: string|null, parent: string|null} */
    public function toResponse(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'title' => $this->title, 'reference' => $this->reference,
            'subtitle' => $this->subtitle, 'state' => $this->state, 'parent' => $this->parent];
    }
}
