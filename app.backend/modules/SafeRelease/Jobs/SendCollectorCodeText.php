<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Jobs;

use Acadly\Kernel\Notify\Channels\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Sends a one-day collector their code by SMS (D-108), after commit, on the
 * queue. The payload is encrypted because it carries the code. A code is not
 * a notice: it goes only to the phone the family typed, and it is never kept
 * in the list of messages sent. A collector ended, or a day passed, is not sent.
 */
final class SendCollectorCodeText implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $collectorId,
        public readonly string $day,
        public readonly string $phone,
        public readonly string $text,
    ) {
        $this->afterCommit();
    }

    public function handle(SmsProvider $sms): void
    {
        $live = DB::table('release_collectors')->where('id', $this->collectorId)->where('valid_on', $this->day)->whereNull('ended_at')->exists();

        if ($live) {
            $sms->send($this->phone, $this->text);
        }
    }
}
