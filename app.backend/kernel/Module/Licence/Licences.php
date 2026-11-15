<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Licence;

use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Reads and verifies the deployment's licence.
 *
 * A licence is a JSON document signed by IQ-X with Ed25519. The deployment
 * holds only the public key (`ACADLY_LICENCE_PUBLIC_KEY`), so it can check a
 * licence but cannot make or enlarge one. Installed licences are kept as
 * append-only rows; the newest one that verifies is in force.
 *
 * Document: {"licence": {"serial", "licensee", "modules": [...], "limits": {...},
 * "issued_at", "expires_at"}, "signature": base64}. The signature covers the
 * licence object with its keys sorted.
 */
final class Licences
{
    private ?Licence $current = null;

    public function __construct(private readonly Clock $clock) {}

    public function current(): Licence
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $publicKey = $this->publicKey();

        if ($publicKey === null) {
            return $this->current = app()->environment(['local', 'testing']) ? Licence::development() : Licence::missing();
        }

        $rows = DB::table('system_licences')->orderByDesc('installed_at')->limit(10)->get(['document']);

        foreach ($rows as $row) {
            try {
                return $this->current = $this->read(is_string($row->document) ? $row->document : '', $publicKey);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $this->current = Licence::missing();
    }

    /**
     * Verifies a document and returns the licence it grants.
     *
     * @throws InvalidArgumentException when the document is malformed or the signature does not verify
     */
    public function read(string $document, ?string $publicKey = null): Licence
    {
        $publicKey ??= $this->publicKey() ?? throw new InvalidArgumentException('No licence key is configured for this deployment.');

        if ($publicKey === '' || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('The licence key of this deployment is not valid.');
        }

        try {
            $parsed = json_decode($document, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The licence is not a valid document.');
        }

        $body = is_array($parsed) ? ($parsed['licence'] ?? null) : null;
        $signature = is_array($parsed) && is_string($parsed['signature'] ?? null) ? base64_decode($parsed['signature'], true) : false;

        if (! is_array($body) || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidArgumentException('The licence is not a valid document.');
        }

        if (! sodium_crypto_sign_verify_detached($signature, self::canonical($body), $publicKey)) {
            throw new InvalidArgumentException('The licence signature does not verify.');
        }

        $modules = array_values(array_filter(is_array($body['modules'] ?? null) ? $body['modules'] : [], 'is_string'));
        $limits = [];

        foreach (is_array($body['limits'] ?? null) ? $body['limits'] : [] as $name => $value) {
            if (is_string($name) && is_int($value)) {
                $limits[$name] = $value;
            }
        }

        $expiresAt = is_string($body['expires_at'] ?? null) ? CarbonImmutable::parse($body['expires_at'])->utc() : null;

        return new Licence(
            $expiresAt !== null && $expiresAt <= $this->clock->now() ? LicenceStatus::Expired : LicenceStatus::Valid,
            is_string($body['serial'] ?? null) ? $body['serial'] : null,
            is_string($body['licensee'] ?? null) ? $body['licensee'] : null,
            $modules,
            $limits,
            $expiresAt,
        );
    }

    /** Forget what this request has read, after a new licence is installed. */
    public function forget(): void
    {
        $this->current = null;
    }

    /**
     * The bytes the signature covers: the licence object, keys sorted, compact.
     *
     * @param  array<mixed>  $body
     */
    public static function canonical(array $body): string
    {
        ksort($body);

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function publicKey(): ?string
    {
        $configured = config('acadly.licence_public_key');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        $key = base64_decode($configured, true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }
}
