<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Engine\Jobs;

use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one after-commit effect of a performed step (rule B6). Dispatched
 * only once the step is committed. Safe to run again: the effect is applied
 * under its idempotency key, once.
 */
final class ApplyAfterCommitEffect implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $produced
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $subjectId,
        public readonly ?string $actorId,
        public readonly string $step,
        public readonly string $outcome,
        public readonly string $fromState,
        public readonly int $index,
        public readonly string $key,
        public readonly string $completionId,
        public readonly array $input,
        public readonly array $produced,
    ) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900, 1800, 3600];
    }

    public function handle(JourneyEngine $engine): void
    {
        $engine->applyAfterCommit($this);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('An after-commit journey effect failed.', [
            'journey' => $this->ref,
            'step' => $this->step,
            'outcome' => $this->outcome,
            'effect_index' => $this->index,
            'completion_id' => $this->completionId,
            'error' => $e !== null ? $e::class : null,
        ]);
    }
}
