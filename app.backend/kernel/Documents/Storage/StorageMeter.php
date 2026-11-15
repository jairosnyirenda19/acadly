<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Storage;

use Acadly\Kernel\Documents\Topics\StorageFilling;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The licence's storage limit (ported from eOffice). What counts is every
 * live blob once, as stored: the same content uploaded twice costs once.
 * At the limit, only kinds that are not essential are refused. The
 * administrators are told at 80% and 95%, once per crossing.
 */
final class StorageMeter
{
    public const array WARN_AT = [95, 80];

    private const string CACHE = 'acadly.documents.storage_used';

    private const string FLAG = 'documents.storage_warned';

    public function __construct(
        private readonly Licences $licences,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {}

    public function used(): int
    {
        return Cache::remember(self::CACHE, 120, fn (): int => (int) DB::table('blobs')->whereNull('released_at')->sum('stored_size'));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** The limit in bytes, or null when the licence sets none. */
    public function limit(): ?int
    {
        $gb = $this->licences->current()->limits['storage_gb'] ?? null;

        return $gb === null ? null : $gb * 1024 ** 3;
    }

    /** Whether new bytes of this size and content fit. Content already stored always fits. */
    public function hasRoom(int $bytes, string $sha): bool
    {
        $limit = $this->limit();

        if ($limit === null || $this->used() + $bytes <= $limit) {
            return true;
        }

        return DB::table('blobs')->where('sha256', $sha)->whereNull('released_at')->exists();
    }

    /** Tells the administrators when a mark is crossed. Returns the level now: 0, 80 or 95. */
    public function warnIfNeeded(): int
    {
        $this->forget();
        $limit = $this->limit();
        $percent = $limit === null || $limit === 0 ? 0 : (int) floor($this->used() * 100 / $limit);
        $level = 0;

        foreach (self::WARN_AT as $mark) {
            if ($percent >= $mark) {
                $level = $mark;

                break;
            }
        }

        $stored = DB::table('system_flags')->where('name', self::FLAG)->value('value');
        $warned = is_string($stored) && ctype_digit($stored) ? (int) $stored : 0;

        if ($level > $warned) {
            $this->notifier->send(new StorageFilling($level, $percent, $this->clock->now()->format('Y-m-d')));
        }

        if ($level !== $warned) {
            DB::table('system_flags')->upsert([['name' => self::FLAG, 'value' => (string) $level, 'updated_at' => $this->clock->now()]],
                ['name'], ['value', 'updated_at']);
        }

        return $level;
    }
}
