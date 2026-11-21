<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Lectures\Application\Actions\SessionActions;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lectures on a person's Today (D-102, D-100): the lecture they give now or
 * next, with its attendance; the cover they are asked for; and, for those
 * who arrange cover, the lectures this week that still need it.
 *
 * @phpstan-import-type Part from TodayItems
 */
final class LecturesToday implements TodayItems
{
    public function __construct(
        private readonly LectureDays $days,
        private readonly LectureViews $views,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'lectures';
    }

    public function today(DashboardScope $scope): array
    {
        if ($scope->school) {
            return [];
        }
        $viewer = $scope->viewer;
        $now = SessionActions::local($this->clock)->format('H:i');
        $part = ['now' => [], 'items' => []];
        foreach ($scope->sees(LecturesPermission::Teach) as $d) {
            if (! DB::table('lecture_slots')->where('division_id', $d)->exists()) {
                continue;
            }
            $offerings = $this->views->offeringsOn($d, $scope->day);
            $next = null;
            foreach ($this->days->on([$d], $scope->day) as $l) {
                if ($l['teacher_id'] === $viewer->id && LectureDays::given($l, $scope->day) && $l['ends'] > $now) {
                    $next = $l;
                    break;
                }
            }
            if ($next !== null) {
                $row = $this->views->row($next, $offerings);
                $taken = $row['taken'];
                $part['now'][] = ['key' => 'lecture:'.$next['slot_id'], 'order' => $next['starts'] <= $now ? 4 : 18,
                    'label' => ($next['starts'] <= $now ? 'Now' : 'Next').' · '.$next['starts'].'–'.$next['ends'], 'title' => $row['code'].' · '.ucfirst($next['kind']),
                    'sub' => $next['room'].' · '.$row['students'].' students',
                    'state' => $taken === null ? ['tone' => 'attention', 'text' => 'Attendance not open'] : ($taken['scanning'] ? ['tone' => 'attention', 'text' => 'Scanning open']
                        : ['tone' => 'done', 'text' => $taken['present'].' signed in']),
                    'action' => $taken === null ? 'Open attendance' : 'Open the lecture', 'href' => '/lectures/l/'.$next['slot_id'].'/'.$next['day']];
            }
            $asked = 0;
            $need = 0;
            $arranges = in_array($d, $scope->sees(LecturesPermission::Cover), true);
            foreach (range(0, 6) as $i) {
                $day = CarbonImmutable::parse($scope->day)->addDays($i)->toDateString();
                foreach ($this->days->on([$d], $day) as $l) {
                    if ($l['day'] !== $day) {
                        continue;
                    }
                    $asked += $l['state'] === 'asked' && $l['asked_id'] === $viewer->id ? 1 : 0;
                    $need += $arranges && $l['state'] === 'away' ? 1 : 0;
                }
            }
            if ($asked > 0) {
                $part['items'][] = ['key' => 'cover_asked', 'order' => 8, 'title' => 'Asked to cover', 'sub' => $asked === 1 ? '1 lecture to accept or decline' : "{$asked} lectures to accept or decline",
                    'count' => (string) $asked, 'tone' => 'attention', 'href' => '/lectures/my-day'];
            }
            if ($need > 0) {
                $part['items'][] = ['key' => 'cover_need', 'order' => 9, 'title' => 'Lectures needing cover', 'sub' => 'In the next 7 days', 'count' => '! '.$need, 'tone' => 'attention',
                    'href' => '/lectures/cover'];
            }
        }

        return $part;
    }
}
