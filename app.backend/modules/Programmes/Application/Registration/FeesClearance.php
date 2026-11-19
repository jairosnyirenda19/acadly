<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Acadly\Modules\Programmes\Domain\Registration;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * The fees gate of registration (D-098): the share of what a student owes
 * that the college needs paid first, read through the Fees contract. With
 * Fees off, or nothing charged, the gate is open. Registration allowed
 * without it holds who allowed it and why.
 *
 * @phpstan-type Clearance array{known: bool, share: int, owed: int, paid: int, needed: int, more: int, cleared: bool, allowed: array{by: string, reason: string, at: string}|null}
 */
final class FeesClearance
{
    public function __construct(
        private readonly ModuleActivation $modules,
        private readonly Container $container,
    ) {}

    /** @return Clearance */
    public function of(Registration $r): array
    {
        $share = Windows::of($r->period_id)['fees_share'] ?? 0;
        $allowed = null;

        if ($r->fees_allowed_by !== null) {
            $by = DB::table('users')->where('id', $r->fees_allowed_by)->first(['name']);
            $allowed = ['by' => $by !== null ? Row::text($by, 'name') : '', 'reason' => (string) $r->fees_allowed_reason, 'at' => (string) $r->fees_allowed_at?->toIso8601String()];
        }

        if (! $this->modules->isActive('fees') || ! $this->container->bound(FeesStanding::class)) {
            return ['known' => false, 'share' => $share, 'owed' => 0, 'paid' => 0, 'needed' => 0, 'more' => 0, 'cleared' => true, 'allowed' => $allowed];
        }

        $s = $this->container->make(FeesStanding::class)->owedAndPaid($r->learner_id);
        $needed = intdiv($s['owed'] * $share + 99, 100);
        $more = max(0, $needed - $s['paid']);

        return ['known' => true, 'share' => $share, 'owed' => $s['owed'], 'paid' => $s['paid'], 'needed' => $needed, 'more' => $more,
            'cleared' => $more === 0 || $allowed !== null, 'allowed' => $allowed];
    }
}
