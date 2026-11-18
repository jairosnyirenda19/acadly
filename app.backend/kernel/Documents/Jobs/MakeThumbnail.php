<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Jobs;

use Acadly\Kernel\Documents\Thumbnails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Draws the picture of an image version, after the upload is committed. Doing it twice makes one picture. */
final class MakeThumbnail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $fileId) {}

    public function handle(Thumbnails $thumbnails): void
    {
        $thumbnails->fromImage($this->fileId);
    }
}
