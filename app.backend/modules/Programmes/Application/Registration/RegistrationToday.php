<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Core\Institution\Contracts\DashboardScope;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Modules\Programmes\Contracts\ProgrammesPermission;
use Illuminate\Support\Facades\DB;

/**
 * Registration's part of Today (D-100): for those who approve a division's
 * semester registrations, the registrations waiting for them.
 */
final class RegistrationToday implements TodayItems
{
    public function key(): string
    {
        return 'programmes';
    }

    public function today(DashboardScope $scope): array
    {
        $divisions = $scope->school ? [] : $scope->sees(ProgrammesPermission::Approve);
        $waiting = $divisions === [] ? 0 : DB::table('programme_registrations')->whereIn('division_id', $divisions)->where('state', 'submitted')->count();

        return $waiting === 0 ? [] : ['items' => [['key' => 'registrations', 'order' => 14, 'title' => 'Registrations waiting', 'sub' => 'Semester registrations to approve',
            'count' => (string) $waiting, 'tone' => 'attention', 'href' => '/programmes/registration']]];
    }
}
