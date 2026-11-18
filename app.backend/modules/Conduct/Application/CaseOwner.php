<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Documents\DocumentOwner;
use Illuminate\Support\Facades\DB;

/** A serious incident's documents live at the class of the incident. */
final class CaseOwner implements DocumentOwner
{
    public function scopeOf(string $ownerId): ?ScopeRef
    {
        $class = DB::table('conduct_cases')->where('id', $ownerId)->value('class_id');

        return is_string($class) ? ScopeRef::of(ScopeType::ClassGroup, $class) : null;
    }

    public function labelOf(string $ownerId): string
    {
        $number = DB::table('conduct_cases')->where('id', $ownerId)->value('number');

        return is_string($number) ? 'Serious incident '.$number : 'Serious incident';
    }
}
