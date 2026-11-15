<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Scanning;

/**
 * clamd over TCP, speaking INSTREAM (ported from eOffice). The bytes are
 * streamed from the upload's temporary file in small pieces.
 *
 *   zINSTREAM\0, then [4-byte length][piece] …, then four zero bytes
 *   reply: "stream: OK" or "stream: <Signature> FOUND"
 *
 * clamd's StreamMaxLength must be at least the largest upload allowed.
 */
final class ClamavScanner implements VirusScanner
{
    private const int PIECE = 32768;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout,
    ) {}

    public function scan(string $path): ?string
    {
        $file = fopen($path, 'rb');

        if ($file === false) {
            throw new ScannerUnavailable('The upload cannot be read for scanning.');
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);

        if ($socket === false) {
            fclose($file);

            throw new ScannerUnavailable('The virus scanner cannot be reached.');
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->write($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $piece = (string) fread($file, self::PIECE);

                if ($piece !== '') {
                    $this->write($socket, pack('N', strlen($piece)).$piece);
                }
            }

            $this->write($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0 \t\n\r");
        } finally {
            fclose($socket);
            fclose($file);
        }

        if (str_ends_with($reply, ': OK')) {
            return null;
        }

        if (preg_match('/:\s*(.+?)\s+FOUND$/', $reply, $found) === 1) {
            return $found[1];
        }

        throw new ScannerUnavailable('The virus scanner did not give a clear answer.');
    }

    /** @param  resource  $socket */
    private function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) === false) {
            throw new ScannerUnavailable('The virus scanner closed the connection.');
        }
    }
}
