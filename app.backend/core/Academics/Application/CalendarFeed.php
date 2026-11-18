<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application;

use Acadly\Core\Academics\Contracts\AcademicsAudit;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A teacher's lessons in the calendar on their phone (D-089): a private
 * address only they should have, kept as a hash. The feed holds the next
 * eight weeks of their timetable, cover lessons included where the
 * timetable names them.
 */
final class CalendarFeed
{
    public const int WEEKS = 8;

    public function __construct(
        private readonly Timetables $timetables,
        private readonly Calendar $calendar,
        private readonly SchoolProfile $school,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array{on: bool, since: string|null, last_used_at: string|null} */
    public function state(User $who): array
    {
        $feed = DB::table('calendar_feeds')->where('user_id', $who->id)->whereNull('ended_at')->first();

        return ['on' => $feed !== null, 'since' => $feed !== null ? Row::text($feed, 'created_at') : null, 'last_used_at' => $feed !== null ? Row::maybeText($feed, 'last_used_at') : null];
    }

    /** Turns it on, or makes a new address: the old one stops working. Answers the address, shown once. */
    public function on(User $who): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($who, $token): void {
            $now = $this->clock->now();
            DB::table('calendar_feeds')->where('user_id', $who->id)->whereNull('ended_at')->update(['ended_at' => $now]);
            DB::table('calendar_feeds')->insert(['id' => Ids::next(), 'user_id' => $who->id, 'token_hash' => hash('sha256', $token), 'created_at' => $now]);
            $this->audit->record(AcademicsAudit::CalendarOn, subject: $who, actor: $this->audit->actorFor($who));
        });

        return rtrim(config()->string('app.url'), '/').'/api/v1/calendar/'.$token.'.ics';
    }

    public function off(User $who): void
    {
        DB::transaction(function () use ($who): void {
            DB::table('calendar_feeds')->where('user_id', $who->id)->whereNull('ended_at')->update(['ended_at' => $this->clock->now()]);
            $this->audit->record(AcademicsAudit::CalendarOff, subject: $who, actor: $this->audit->actorFor($who));
        });
    }

    /** The feed of an address, or null when the address is not one in force. */
    public function ics(string $token): ?string
    {
        $feed = DB::table('calendar_feeds')->where('token_hash', hash('sha256', $token))->whereNull('ended_at')->first();
        $who = $feed !== null ? User::query()->find(Row::text($feed, 'user_id')) : null;

        if (! $who instanceof User || ! $who->isActive()) {
            return null;
        }

        DB::table('calendar_feeds')->where('id', Row::text($feed, 'id'))->update(['last_used_at' => $this->clock->now()]);
        $today = CarbonImmutable::parse($this->clock->today());
        $classes = [];
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Acadly IQ-X//Lessons//EN', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.self::text($this->school->name().' · '.$who->name)];

        for ($i = 0; $i < self::WEEKS * 7; $i++) {
            $day = $today->addDays($i)->toDateString();

            foreach ($this->timetables->lessonsOf($who->id, $day) as $lesson) {
                $classes[$lesson['class_id']] ??= $this->calendar->classInForce($lesson['class_id'])['name'] ?? '';
                $date = str_replace('-', '', $day);
                $lines = [...$lines, 'BEGIN:VEVENT', 'UID:'.$lesson['slot_id'].'-'.$date.'@acadly', 'DTSTAMP:'.gmdate('Ymd\THis\Z'),
                    'DTSTART:'.$date.'T'.str_replace(':', '', substr($lesson['starts'], 0, 5)).'00', 'DTEND:'.$date.'T'.str_replace(':', '', substr($lesson['ends'], 0, 5)).'00',
                    'SUMMARY:'.self::text($lesson['subject'].' · '.$classes[$lesson['class_id']]), 'DESCRIPTION:'.self::text($lesson['name']), 'END:VEVENT'];
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private static function text(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }
}
