<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Documents\Document;
use Acadly\Kernel\Documents\DocumentPermission;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places or releases a hold: while held, a document cannot be trashed or
 * purged, for an inquiry, a dispute or a legal matter. Both need a reason.
 */
final class HoldDocument
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $by, Document $document, bool $hold, string $reason): void
    {
        $this->access->check($by, DocumentPermission::Manage);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        if ($document->isHeld() === $hold) {
            throw ValidationException::withMessages(['document' => [$hold ? 'The document is already on hold.' : 'The document is not on hold.']]);
        }

        DB::transaction(function () use ($by, $document, $hold, $reason): void {
            $document->forceFill($hold
                ? ['held_at' => $this->clock->now(), 'held_by' => $by->id, 'hold_reason' => $reason]
                : ['held_at' => null, 'held_by' => null, 'hold_reason' => null])->save();

            $this->audit->record($hold ? KernelAudit::DocumentHeld : KernelAudit::HoldReleased, subject: $document,
                reason: $reason, actor: $this->audit->actorFor($by));
        });
    }
}
