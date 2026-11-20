<?php

declare(strict_types=1);

namespace Acadly\Modules\News\Application;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;

/** News on a member of staff's dashboard (D-101): what they have not read yet, in the division worked in. */
final class NewsToday implements TodayItems
{
    public function __construct(private readonly NewsBoard $board) {}

    public function key(): string
    {
        return 'news';
    }

    public function today(DashboardScope $scope): array
    {
        if ($scope->school) {
            return [];
        }
        $unread = [];
        foreach ($scope->ids() as $division) {
            foreach ($this->board->forStaff($scope->viewer->id, $division, false) as $p) {
                if (! $p['read']) {
                    $unread[] = $p;
                }
            }
        }

        return $unread === [] ? [] : ['items' => [['key' => 'news', 'order' => 60, 'title' => 'News', 'sub' => $unread[0]['title'].(count($unread) > 1 ? ' and '.(count($unread) - 1).' more' : ''),
            'count' => count($unread).' new', 'tone' => 'plain', 'href' => '/news']]];
    }
}
