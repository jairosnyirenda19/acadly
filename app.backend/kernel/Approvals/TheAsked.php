<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/** The people a waiting step of an approval asks. */
final class TheAsked implements PartyResolver
{
    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof ApprovalWaiting) {
            return [];
        }

        $step = DB::table('approval_steps')->where('request_id', $topic->requestId)->where('position', $topic->step)->first(['asked', 'state']);

        if ($step === null || Row::text($step, 'state') !== 'waiting') {
            return [];
        }

        $ids = json_decode(Row::text($step, 'asked'), true);

        return array_values(User::query()->whereIn('id', is_array($ids) ? array_filter($ids, is_string(...)) : [])->get()
            ->filter(fn (User $u): bool => $u->isActive())->map(fn (User $u): Recipient => Recipient::user($u))->all());
    }
}
