<?php

declare(strict_types=1);

namespace Acadly\Kernel\Audit;

/**
 * Walks the trail in order and checks it: numbers without gaps, each line
 * linked to the one before, each hash matching its line, and every seal
 * matching the line it names (docs/architecture/10-audit-trail.md §5).
 */
final class ChainVerifier
{
    /** @var list<string> */
    private array $problems = [];

    private int $lines = 0;

    private string $head = Chain::GENESIS;

    /** @var array<int, string> seq => hash, only for lines a seal names */
    private array $sealed = [];

    /** @param  array<int, true>  $sealedSeqs  the line numbers that seals name */
    public function __construct(private readonly array $sealedSeqs = []) {}

    /** @param  array<string, mixed>  $line */
    public function add(array $line): void
    {
        $expected = $this->lines + 1;
        $seq = is_numeric($line['seq'] ?? null) ? (int) $line['seq'] : 0;
        $prev = is_string($line['prev_hash'] ?? null) ? $line['prev_hash'] : '';
        $hash = is_string($line['hash'] ?? null) ? $line['hash'] : '';

        if ($seq !== $expected) {
            $this->problems[] = "Line {$expected} is missing; the next line found is {$seq}.";
        }

        if (! hash_equals($this->head, $prev)) {
            $this->problems[] = "Line {$seq} does not link to the line before it.";
        }

        if (! hash_equals(Chain::hash($prev, $line), $hash)) {
            $this->problems[] = "Line {$seq} has been changed: its hash does not match its content.";
        }

        if (isset($this->sealedSeqs[$seq])) {
            $this->sealed[$seq] = $hash;
        }

        $this->lines = max($this->lines + 1, $seq);
        $this->head = $hash;
    }

    /**
     * @param  list<array{last_seq: int, head_hash: string, line_count: int, valid_signature: bool}>  $seals  oldest first
     * @return list<string>
     */
    public function finish(array $seals): array
    {
        $previous = 0;

        foreach ($seals as $seal) {
            $seq = $seal['last_seq'];

            if (! $seal['valid_signature']) {
                $this->problems[] = "The seal at line {$seq} has an invalid signature.";
            }

            if ($seq < $previous) {
                $this->problems[] = "The seal at line {$seq} names fewer lines than an earlier seal.";
            }

            if ($seal['line_count'] !== $seq) {
                $this->problems[] = "The seal at line {$seq} counts {$seal['line_count']} lines.";
            }

            if ($seq > $this->lines) {
                $this->problems[] = "The seal at line {$seq} names a line that no longer exists.";
            } elseif ($seq > 0 && ! hash_equals($seal['head_hash'], $this->sealed[$seq] ?? '')) {
                $this->problems[] = "The seal at line {$seq} does not match the trail.";
            }

            $previous = $seq;
        }

        return $this->problems;
    }

    public function lines(): int
    {
        return $this->lines;
    }

    public function head(): string
    {
        return $this->head;
    }
}
