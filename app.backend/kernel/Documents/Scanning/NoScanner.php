<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Scanning;

/** Scanning switched off on purpose (`ACADLY_VIRUS_SCAN=off`). Development machines only. */
final class NoScanner implements VirusScanner
{
    public function scan(string $path): ?string
    {
        return null;
    }
}
