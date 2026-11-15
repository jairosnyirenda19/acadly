<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Storage;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Content-addressed, envelope-encrypted storage of document bytes (D-053).
 * Ported from the eOffice blob store, with two changes: bytes go in from a
 * file and come out as a stream, so a large scan never sits whole in memory.
 *
 * - Identity is the SHA-256 of the plain bytes. The same content is stored once.
 * - Each blob has a random key of its own (AES-256-GCM), wrapped by the
 *   deployment key. Rotating the deployment key re-wraps the small keys only.
 * - The bytes are encrypted in chunks, each with its own nonce and tag, and
 *   bound to the blob's hash and the chunk's position: a chunk cannot be
 *   moved, swapped into another blob, or changed without the read failing.
 *
 * On disk, chunk N is [12-byte nonce][ciphertext][16-byte tag].
 * Blob rows are written here and nowhere else.
 */
final class BlobStore
{
    private const int NONCE = 12;

    private const int TAG = 16;

    public function __construct(private readonly Clock $clock) {}

    /** Stores the file at a local path. Returns the blob's hash. Stored content is not stored again. */
    public function put(string $path, string $mimeType): string
    {
        $sha = hash_file('sha256', $path);

        if ($sha === false) {
            throw new RuntimeException('The upload cannot be read.');
        }

        $existing = DB::table('blobs')->where('sha256', $sha)->first(['sha256', 'released_at']);

        if ($existing !== null && $existing->released_at === null) {
            return $sha;
        }

        $key = random_bytes(32);
        $chunk = $this->chunkSize();
        $raw = (string) hex2bin($sha);
        $in = fopen($path, 'rb');
        $out = fopen('php://temp', 'w+b');

        if ($in === false || $out === false) {
            throw new RuntimeException('The upload cannot be read.');
        }

        $index = 0;
        $size = 0;

        try {
            do {
                $plain = (string) fread($in, max(1, $chunk));
                $size += strlen($plain);
                fwrite($out, $this->seal($plain, $key, $raw, $index));
                $index++;
            } while (! feof($in) && $plain !== '');

            $stored = ftell($out);
            rewind($out);
            $this->disk()->writeStream(self::pathFor($sha), $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        [$wrapped, $nonce] = $this->wrap($key, $raw);
        sodium_memzero($key);

        $row = [
            'storage_path' => self::pathFor($sha),
            'byte_size' => $size,
            'stored_size' => $stored === false ? 0 : $stored,
            'mime_type' => $mimeType,
            'chunk_size' => $chunk,
            'wrapped_key' => base64_encode($wrapped),
            'wrap_nonce' => base64_encode($nonce),
            'key_id' => $this->keyId(),
            'released_at' => null,
        ];

        $existing === null
            ? DB::table('blobs')->insertOrIgnore([...$row, 'sha256' => $sha, 'created_at' => $this->clock->now()])
            : DB::table('blobs')->where('sha256', $sha)->update($row);

        return $sha;
    }

    /**
     * The plain bytes, one chunk at a time, each one checked before it is given out.
     *
     * @return Generator<int, string>
     */
    public function read(string $sha): Generator
    {
        $blob = $this->row($sha);
        $chunk = max(1, $blob['chunk_size']);
        $size = $blob['byte_size'];
        $count = max(1, intdiv($size + $chunk - 1, $chunk));
        $stream = $this->disk()->readStream($blob['storage_path']);

        if ($stream === null) {
            throw new RuntimeException("The stored bytes of {$sha} cannot be opened.");
        }

        $key = $this->unwrap($blob);
        $raw = (string) hex2bin($sha);

        try {
            for ($index = 0; $index < $count; $index++) {
                $length = min($chunk, $size - $index * $chunk);
                $frame = (string) stream_get_contents($stream, self::NONCE + max(0, $length) + self::TAG);

                yield $this->open($frame, $key, $raw, $index, $sha);
            }
        } finally {
            fclose($stream);
            sodium_memzero($key);
        }
    }

    /** Whether the stored bytes still decrypt to their hash. */
    public function verify(string $sha): bool
    {
        $context = hash_init('sha256');

        foreach ($this->read($sha) as $plain) {
            hash_update($context, $plain);
        }

        return hash_final($context) === $sha;
    }

    /** No live document uses the blob any more: remove its bytes. The row stays, marked released. */
    public function release(string $sha): void
    {
        $blob = $this->row($sha);

        DB::table('blobs')->where('sha256', $sha)->update(['released_at' => $this->clock->now()]);
        $this->disk()->delete($blob['storage_path']);
    }

    private function seal(string $plain, string $key, string $raw, int $index): string
    {
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $raw.pack('N', $index), self::TAG);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return $nonce.$cipher.$tag;
    }

    private function open(string $frame, string $key, string $raw, int $index, string $sha): string
    {
        if (strlen($frame) < self::NONCE + self::TAG) {
            throw new RuntimeException("Chunk {$index} of {$sha} is truncated.");
        }

        $plain = openssl_decrypt(substr($frame, self::NONCE, -self::TAG), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($frame, 0, self::NONCE), substr($frame, -self::TAG), $raw.pack('N', $index));

        if ($plain === false) {
            throw new RuntimeException("Chunk {$index} of {$sha} does not verify: it was changed or damaged.");
        }

        return $plain;
    }

    /** @return array{string, string} the wrapped key with its tag, and the nonce */
    private function wrap(string $key, string $raw): array
    {
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', $this->deploymentKey(), OPENSSL_RAW_DATA, $nonce, $tag, $raw, self::TAG);

        if ($cipher === false) {
            throw new RuntimeException('Key wrapping failed.');
        }

        return [$cipher.$tag, $nonce];
    }

    /** @param  array{sha256: string, storage_path: string, byte_size: int, chunk_size: int, wrapped_key: string, wrap_nonce: string, key_id: string}  $blob */
    private function unwrap(array $blob): string
    {
        if ($blob['key_id'] !== $this->keyId()) {
            throw new RuntimeException("The stored bytes of {$blob['sha256']} are wrapped with another key. Rotate the keys first.");
        }

        $wrapped = (string) base64_decode($blob['wrapped_key'], true);
        $key = openssl_decrypt(substr($wrapped, 0, -self::TAG), 'aes-256-gcm', $this->deploymentKey(), OPENSSL_RAW_DATA,
            (string) base64_decode($blob['wrap_nonce'], true), substr($wrapped, -self::TAG), (string) hex2bin($blob['sha256']));

        if ($key === false) {
            throw new RuntimeException("The key of {$blob['sha256']} cannot be unwrapped.");
        }

        return $key;
    }

    /** @return array{sha256: string, storage_path: string, byte_size: int, chunk_size: int, wrapped_key: string, wrap_nonce: string, key_id: string} */
    private function row(string $sha): array
    {
        $blob = DB::table('blobs')->where('sha256', $sha)->first();

        if ($blob === null || $blob->released_at !== null) {
            throw new RuntimeException("The stored bytes of {$sha} are not available.");
        }

        return [
            'sha256' => Row::text($blob, 'sha256'),
            'storage_path' => Row::text($blob, 'storage_path'),
            'byte_size' => Row::int($blob, 'byte_size'),
            'chunk_size' => Row::int($blob, 'chunk_size'),
            'wrapped_key' => Row::text($blob, 'wrapped_key'),
            'wrap_nonce' => Row::text($blob, 'wrap_nonce'),
            'key_id' => Row::text($blob, 'key_id'),
        ];
    }

    /** The deployment key. A development or test machine without one derives it from the application key. */
    private function deploymentKey(): string
    {
        $configured = config('acadly.documents.key');
        $key = is_string($configured) && $configured !== '' ? base64_decode($configured, true) : false;

        if (is_string($key) && strlen($key) === 32) {
            return $key;
        }

        if (app()->environment(['local', 'testing'])) {
            return hash('sha256', 'acadly-documents|'.config()->string('app.key'), true);
        }

        throw new RuntimeException('Documents cannot be stored: ACADLY_DOCUMENTS_KEY must be 32 random bytes in base64.');
    }

    private function keyId(): string
    {
        return config()->string('acadly.documents.key_id');
    }

    private function chunkSize(): int
    {
        return max(64 * 1024, config()->integer('acadly.documents.chunk_size'));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config()->string('acadly.documents.disk'));
    }

    private static function pathFor(string $sha): string
    {
        return substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    }
}
