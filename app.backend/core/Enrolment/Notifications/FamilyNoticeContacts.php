<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Notifications;

use Acadly\Core\Enrolment\Application\FamilyNotices;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Illuminate\Support\Facades\DB;

/** Each family a notice reaches, once: the main contact of each learner, by phone (D-115). */
final class FamilyNoticeContacts implements PartyResolver
{
    public function __construct(private readonly FamilyNotices $notices) {}

    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof FamilyNoticeSent) {
            return [];
        }

        $row = DB::table('family_notices')->where('id', $topic->noticeId)->first();

        if ($row === null) {
            return [];
        }

        $out = [];

        foreach ($this->notices->mainContacts($this->notices->learners(FamilyNotices::audienceOf($row))) as $c) {
            $out[] = new Recipient($c['name'], null, null, $c['phone']);
        }

        return $out;
    }
}
