<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Jobs;

use Acadly\Kernel\Documents\Pages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Draws the pages of a new version as pictures, after the upload is committed (D-103). Doing it twice draws once. */
final class RenderPages implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Document work is not web work: a long file takes minutes. */
    public int $timeout = 600;

    public function __construct(public readonly string $fileId) {}

    public function handle(Pages $pages): void
    {
        set_time_limit(0);
        $pages->render($this->fileId);
    }
}
