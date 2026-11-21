<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Illuminate\Validation\ValidationException;

/**
 * The attendance phone's passkey (D-102): the phone's own lock (fingerprint,
 * face or PIN) signs a challenge, and the server checks the signature with
 * the public key kept when the phone was set up. Nothing secret is kept.
 * Phones sign with ES256 (P-256 and SHA-256); that is the one kind accepted.
 * The attestation of the phone's maker is not relied on: the student who
 * signed in sets up their own phone.
 */
final class Passkeys
{
    private const int USER_PRESENT = 0x01;

    private const int USER_VERIFIED = 0x04;

    private const int CREDENTIAL_DATA = 0x40;

    /** The host the passkeys belong to: the student portal's. */
    public static function rpId(): string
    {
        $host = parse_url(config()->string('acadly.student_url', 'http://localhost:5175'), PHP_URL_HOST);

        return is_string($host) ? $host : 'localhost';
    }

    public static function origin(): string
    {
        $url = parse_url(config()->string('acadly.student_url', 'http://localhost:5175'));
        $scheme = is_array($url) && isset($url['scheme']) ? $url['scheme'] : 'http';
        $host = is_array($url) && isset($url['host']) ? $url['host'] : 'localhost';
        $port = is_array($url) && isset($url['port']) ? ':'.$url['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * Checks a new passkey made by the phone, and gives its id and public key.
     *
     * @return array{credential_id: string, public_key: string, sign_count: int}
     */
    public static function register(string $clientDataJson, string $attestationObject, string $challenge): array
    {
        self::client(self::decode($clientDataJson), 'webauthn.create', $challenge);
        $att = (new Cbor(self::decode($attestationObject)))->read();
        $authData = is_array($att) && is_string($att['authData'] ?? null) ? $att['authData'] : self::refuse();
        [$flags, $count] = self::authData($authData);
        if (($flags & self::CREDENTIAL_DATA) === 0 || strlen($authData) < 55) {
            self::refuse();
        }
        $length = unpack('n', substr($authData, 53, 2));
        $idLength = is_array($length) && is_int($length[1] ?? null) ? $length[1] : 0;
        $credentialId = substr($authData, 55, $idLength);
        if ($idLength === 0 || strlen($credentialId) !== $idLength) {
            self::refuse();
        }
        $key = (new Cbor(substr($authData, 55 + $idLength)))->read();
        if (! is_array($key) || ($key[1] ?? null) !== 2 || ($key[3] ?? null) !== -7 || ($key[-1] ?? null) !== 1
            || ! is_string($key[-2] ?? null) || ! is_string($key[-3] ?? null) || strlen($key[-2]) !== 32 || strlen($key[-3]) !== 32) {
            throw ValidationException::withMessages(['phone' => ['This phone\'s lock cannot be used. Use a phone with a fingerprint, face or PIN lock.']]);
        }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').chr(4).$key[-2].$key[-3];
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----'."\n";

        return ['credential_id' => self::encode($credentialId), 'public_key' => $pem, 'sign_count' => $count];
    }

    /**
     * Checks a signature from the attendance phone, and gives the phone's new count.
     */
    public static function verify(string $publicKey, int $signCount, string $clientDataJson, string $authenticatorData, string $signature, string $challenge): int
    {
        $client = self::decode($clientDataJson);
        self::client($client, 'webauthn.get', $challenge);
        $authData = self::decode($authenticatorData);
        [, $count] = self::authData($authData);
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false || openssl_verify($authData.hash('sha256', $client, true), self::decode($signature), $key, OPENSSL_ALGO_SHA256) !== 1) {
            self::refuse();
        }
        // A phone that counts its signatures counts up; a count that goes back is a copied key.
        if (($count > 0 || $signCount > 0) && $count <= $signCount) {
            self::refuse();
        }

        return $count;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? self::refuse() : $bytes;
    }

    /** @return array<string, mixed> */
    private static function client(string $json, string $type, string $challenge): array
    {
        $c = json_decode($json, true);
        if (! is_array($c) || ($c['type'] ?? null) !== $type || ! is_string($c['challenge'] ?? null) || ! hash_equals($challenge, $c['challenge'])
            || ($c['origin'] ?? null) !== self::origin()) {
            self::refuse();
        }

        /** @var array<string, mixed> $c */
        return $c;
    }

    /** @return array{0: int, 1: int} the flags and the signature count */
    private static function authData(string $authData): array
    {
        if (strlen($authData) < 37 || ! hash_equals(hash('sha256', self::rpId(), true), substr($authData, 0, 32))) {
            self::refuse();
        }
        $flags = ord($authData[32]);
        if (($flags & self::USER_PRESENT) === 0 || ($flags & self::USER_VERIFIED) === 0) {
            throw ValidationException::withMessages(['phone' => ['Unlock with the phone\'s fingerprint, face or PIN.']]);
        }
        $count = unpack('N', substr($authData, 33, 4));

        return [$flags, is_array($count) && is_int($count[1] ?? null) ? $count[1] : 0];
    }

    private static function refuse(): never
    {
        throw ValidationException::withMessages(['phone' => ['This is not your attendance phone.']]);
    }
}
