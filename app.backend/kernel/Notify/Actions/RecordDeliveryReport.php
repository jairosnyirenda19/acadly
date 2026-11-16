<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Notify\MessageSettings;
use Acadly\Kernel\Notify\NoticeDispatcher;
use Illuminate\Http\Request;

/**
 * A provider reports what became of a message. The address names a secret
 * token, so a report from anyone else is ignored. A delivered message stays
 * delivered, whatever arrives after.
 */
#[NotAudited('A delivery report settles a message row; the row is the record, and it is shown on the Sent messages page.')]
final class RecordDeliveryReport
{
    public function __construct(
        private readonly MessageSettings $settings,
        private readonly NoticeDispatcher $dispatcher,
    ) {}

    public function handle(string $token, Request $request): void
    {
        $report = $this->settings->providerForReceipts($token)?->report($request);

        if ($report !== null) {
            $this->dispatcher->report($report);
        }
    }
}
