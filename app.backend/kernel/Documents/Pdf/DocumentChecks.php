<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Pdf;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The check codes of issued documents (D-096). A code is three groups of four
 * letters and digits, without the ones people misread (0 O 1 I L). The same
 * document keeps its code however often it is printed.
 */
final class DocumentChecks
{
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Clock $clock) {}

    /** The document's code, made the first time it is issued. */
    public function codeFor(string $kind, string $reference, string $issuedOn, ?string $by): string
    {
        $found = DB::table('document_checks')->where('kind', $kind)->where('reference', $reference)->value('code');

        if (is_string($found)) {
            return $found;
        }

        for ($try = 0; $try < 5; $try++) {
            $code = self::fresh();

            if (DB::table('document_checks')->insertOrIgnore(['id' => Ids::next(), 'code' => $code, 'kind' => $kind, 'reference' => $reference,
                'issued_on' => $issuedOn, 'issued_by' => $by, 'created_at' => $this->clock->now()]) === 1) {
                return $code;
            }

            // Another request issued the same document at the same moment.
            $found = DB::table('document_checks')->where('kind', $kind)->where('reference', $reference)->value('code');

            if (is_string($found)) {
                return $found;
            }
        }

        throw new \RuntimeException('No check code could be issued.');
    }

    /** Marks a document cancelled, by the document that cancelled it. Its code then says so. */
    public function cancel(string $kind, string $reference, string $on, string $by): void
    {
        DB::table('document_checks')->where('kind', $kind)->where('reference', $reference)->whereNull('cancelled_on')
            ->update(['cancelled_on' => $on, 'cancelled_by_reference' => $by]);
    }

    /**
     * What the public check page may say of a code: never a name, an amount or a mark.
     *
     * @return array{kind: string, reference: string, issued_on: string, cancelled: array{on: string, by: string}|null}|null
     */
    public function check(string $code): ?array
    {
        $row = DB::table('document_checks')->where('code', self::normalise($code))->first();

        if ($row === null) {
            return null;
        }

        $cancelled = Row::maybeText($row, 'cancelled_on');

        return [
            'kind' => Row::text($row, 'kind'),
            'reference' => Row::text($row, 'reference'),
            'issued_on' => substr(Row::text($row, 'issued_on'), 0, 10),
            'cancelled' => $cancelled === null ? null : ['on' => substr($cancelled, 0, 10), 'by' => Row::maybeText($row, 'cancelled_by_reference') ?? ''],
        ];
    }

    /** A code as people type it: any case, spaces or dashes. */
    public static function normalise(string $code): string
    {
        $plain = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return strlen($plain) === 12 ? implode('-', str_split($plain, 4)) : $plain;
    }

    private static function fresh(): string
    {
        $out = '';

        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($out, 4));
    }
}
