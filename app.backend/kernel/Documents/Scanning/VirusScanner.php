<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Scanning;

/**
 * Malware scanning of uploaded bytes. A socket, not a dependency: a
 * deployment talks to clamd, a development machine may switch scanning off,
 * and a deployment with no scanner set up refuses every upload.
 */
interface VirusScanner
{
    /**
     * The signature name when the file is infected, null when it is clean.
     *
     * @throws ScannerUnavailable when the scanner cannot be reached
     */
    public function scan(string $path): ?string;
}
