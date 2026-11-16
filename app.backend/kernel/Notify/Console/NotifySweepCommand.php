<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Console;

use Acadly\Kernel\Notify\DeliveryStatus;
use Acadly\Kernel\Notify\Jobs\DeliverNotice;
use Acadly\Kernel\Notify\Jobs\ResolveNotice;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Notify\Topics\DeliveriesWaiting;
use Acadly\Kernel\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs every minute (04 §7):
 *  - resolves any sent notice that a stopped worker left unresolved;
 *  - when deliveries have been queued for more than an hour, tells the
 *    staff who look after delivery, at most once an hour.
 */
final class NotifySweepCommand extends Command
{
    protected $signature = 'notify:sweep';

    protected $description = 'Resolve notices left behind and report deliveries that are waiting';

    public function handle(Notifier $notifier, MessageSettings $settings, Clock $clock): int
    {
        $now = $clock->now();

        $left = DB::table('notification_requests as r')
            ->leftJoin('notification_resolutions as s', 's.request_id', '=', 'r.id')
            ->whereNull('s.request_id')
            ->where('r.requested_at', '<', $now->subMinute())
            ->orderBy('r.requested_at')
            ->limit(500)
            ->pluck('r.id');

        foreach ($left as $id) {
            if (is_string($id)) {
                ResolveNotice::dispatch($id);
            }
        }

        // Messages held for quiet hours go once the hours are over.
        if ($settings->quietUntil($now) === null) {
            foreach (DB::table('notification_deliveries')->where('status', DeliveryStatus::Queued->value)->where('reason', 'quiet_hours')->limit(500)->pluck('id') as $id) {
                if (is_string($id)) {
                    DeliverNotice::dispatch($id);
                }
            }
        }

        $waiting = DB::table('notification_deliveries')
            ->where('status', DeliveryStatus::Queued->value)
            ->where(fn (Builder $q): Builder => $q->whereNull('reason')->orWhere('reason', '!=', 'quiet_hours'))
            ->where('queued_at', '<', $now->subHour())
            ->count();

        if ($waiting > 0) {
            $notifier->send(new DeliveriesWaiting($waiting, $now->utc()->format('Y-m-d\TH')));
        }

        $this->components->info("Notices resolved again: {$left->count()}. Deliveries waiting more than one hour: {$waiting}.");

        return self::SUCCESS;
    }
}
