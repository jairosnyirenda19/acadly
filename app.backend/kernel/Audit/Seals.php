<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Verifies the trail and seals its head (docs/architecture/10-audit-trail.md §5).
 *
 * A seal is signed with the deployment's key and written twice: to the
 * database and to a file outside it. A later seal that names fewer lines, or
 * a head that the chain no longer reaches, proves that lines were removed.
 */
final class Seals
{
    public const string FOLDER = 'audit-seals';

    public function __construct(
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array{problems: list<string>, lines: int, head: string} */
    public function verify(): array
    {
        $seals = DB::table('audit_seals')->orderBy('last_seq')->orderBy('sealed_at')->get();
        $sealedSeqs = [];

        foreach ($seals as $seal) {
            $sealedSeqs[self::int($seal->last_seq)] = true;
        }

        $verifier = new ChainVerifier($sealedSeqs);

        foreach (DB::table('audit_events')->orderBy('seq')->lazy(500) as $line) {
            $row = [];

            foreach ([...Chain::COLUMNS, 'prev_hash', 'hash'] as $column) {
                $row[$column] = $line->{$column} ?? null;
            }

            $verifier->add($row);
        }

        $checked = [];
        $problems = [];

        foreach ($seals as $seal) {
            $lastSeq = self::int($seal->last_seq);
            $head = self::text($seal->head_hash);
            $signature = self::text($seal->signature);
            $record = self::record($lastSeq, $head, self::int($seal->line_count), Chain::time($seal->sealed_at) ?? '');

            $checked[] = [
                'last_seq' => $lastSeq,
                'head_hash' => $head,
                'line_count' => $record['line_count'],
                'valid_signature' => hash_equals($this->sign($record), $signature),
            ];

            $file = self::FOLDER.'/'.self::text($seal->id).'.json';

            if (Storage::exists($file) && Storage::get($file) !== self::file($record, $signature)) {
                $problems[] = "The seal at line {$lastSeq} differs from its copy outside the database.";
            }
        }

        return [
            'problems' => [...$verifier->finish($checked), ...$problems],
            'lines' => $verifier->lines(),
            'head' => $verifier->head(),
        ];
    }

    /**
     * Verify, then seal. A trail that does not verify is not sealed: the
     * failure is written to the trail and the caller is told.
     *
     * @return array{sealed: bool, problems: list<string>, lines: int}
     */
    public function seal(): array
    {
        $result = $this->verify();

        if ($result['problems'] !== []) {
            $this->audit->record(KernelAudit::ChainBroken, outcome: Outcome::Failed, actor: Actor::system(), meta: [
                'problems' => count($result['problems']),
                'first_problem' => $result['problems'][0],
            ]);

            return ['sealed' => false, 'problems' => $result['problems'], 'lines' => $result['lines']];
        }

        $id = Ids::next();
        $record = self::record($result['lines'], $result['head'], $result['lines'], Chain::time($this->clock->now()) ?? '');
        $signature = $this->sign($record);

        DB::transaction(function () use ($id, $record, $signature): void {
            DB::table('audit_seals')->insert([
                'id' => $id,
                'sealed_at' => $record['sealed_at'],
                'last_seq' => $record['last_seq'],
                'head_hash' => $record['head_hash'],
                'line_count' => $record['line_count'],
                'signature' => $signature,
            ]);

            $this->audit->record(KernelAudit::Sealed, actor: Actor::system(), meta: ['last_seq' => $record['last_seq']]);
        });

        Storage::put(self::FOLDER.'/'.$id.'.json', self::file($record, $signature));

        return ['sealed' => true, 'problems' => [], 'lines' => $result['lines']];
    }

    /** @return array{last_seq: int, head_hash: string, line_count: int, sealed_at: string} */
    private static function record(int $lastSeq, string $head, int $count, string $sealedAt): array
    {
        return ['last_seq' => $lastSeq, 'head_hash' => $head, 'line_count' => $count, 'sealed_at' => $sealedAt];
    }

    /** @param  array{last_seq: int, head_hash: string, line_count: int, sealed_at: string}  $record */
    private static function file(array $record, string $signature): string
    {
        return json_encode([...$record, 'signature' => $signature], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param  array{last_seq: int, head_hash: string, line_count: int, sealed_at: string}  $record */
    private function sign(array $record): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('The deployment has no key to sign the seal with.');
        }

        return hash_hmac('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
