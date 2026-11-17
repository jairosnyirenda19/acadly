<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Jobs;

use Acadly\Kernel\Notify\Channels\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Sends one sign-in code by SMS, after commit, on the queue. The payload is
 * encrypted because it carries the code. A code already used or expired is
 * not sent, so a retry never sends a stale code.
 *
 * A code is not a notice: it goes to the number that asked for it, and it is
 * never kept in the list of messages sent.
 */
final class SendSignInCodeText implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $codeId,
        public readonly string $phone,
        public readonly string $text,
    ) {
        $this->afterCommit();
    }

    public function handle(SmsProvider $sms): void
    {
        $live = DB::table('sign_in_codes')->where('id', $this->codeId)->whereNull('used_at')->where('expires_at', '>', now())->exists();

        if ($live) {
            $sms->send($this->phone, $this->text);
        }
    }
}
