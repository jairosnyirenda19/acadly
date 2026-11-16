<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Identity\User;

/**
 * One kind of request that needs someone else's yes (the approved Approvals
 * design), declared by the module that owns it and tagged `acadly.approval-topics`.
 * It shows in the catalogue while its module is on.
 *
 * The chain decides who is asked, in steps. The module keeps its journey:
 * when the last step says yes, or any step says no, `settle()` performs the
 * journey's own decision step as the person who answered last.
 */
interface ApprovalTopic
{
    public const string TAG = 'acadly.approval-topics';

    /** For example `attendance.correction`. Starts with the module's key. */
    public function key(): string;

    /** The module that owns it; the topic shows only while the module is on. */
    public function module(): string;

    /** The area of school life it is listed under, in the catalogue. */
    public function area(): string;

    public function title(): string;

    public function description(): string;

    /** Who may decide this at all. Only holders of it, where it happened, are ever asked. */
    public function permission(): Permission;

    /**
     * The people the module can name for a request, by key and label: the class teacher, the subject teacher.
     *
     * @return array<string, string>
     */
    public function relations(): array;

    /**
     * The facts a step may depend on, by key, as the words that finish "Only when …".
     *
     * @return array<string, string>
     */
    public function facts(): array;

    /** The request, for the chain and the screens; null when it is gone. */
    public function case(string $subjectId): ?ApprovalCase;

    /** Performs the module's own decision, once the chain has decided. */
    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void;
}
