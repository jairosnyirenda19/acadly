<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Scanning;

/** No scanner is set up for this deployment. Every upload is refused until one is. */
final class MissingScanner implements VirusScanner
{
    public function scan(string $path): ?string
    {
        throw new ScannerUnavailable('No virus scanner is set up.');
    }
}
