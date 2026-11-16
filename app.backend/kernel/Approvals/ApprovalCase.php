<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Access\ScopeRef;

/** One request as its module describes it: who asked, where, what changes, and the facts a chain may test. */
final readonly class ApprovalCase
{
    /**
     * @param  array<string, list<string>>  $related  user ids by relation key: the class teacher, the subject teachers
     * @param  array<string, bool>  $facts
     */
    public function __construct(
        public string $requesterId,
        public ScopeRef $at,
        public string $title,
        public string $where,
        public ?string $reason,
        public string $link,
        public array $related = [],
        public array $facts = [],
    ) {}
}
