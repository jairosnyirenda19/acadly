<?php

declare(strict_types=1);

/*
| The layer rules of docs/architecture/07-engineering-rules.md, section A.
| A pull request that breaks one of these fails here.
*/

arch('A1 · the kernel depends on no domain')
    ->expect('Acadly\Kernel')
    ->not->toUse(['Acadly\Core', 'Acadly\Modules', 'App']);

arch('A1 · core domains do not depend on optional modules')
    ->expect('Acadly\Core')
    ->not->toUse(['Acadly\Modules', 'App']);

arch('A7 · the application shell holds no domain code')
    ->expect('App')
    ->not->toUse(['Acadly\Core', 'Acadly\Modules']);

arch('every Acadly file uses strict types')
    ->expect('Acadly')
    ->toUseStrictTypes();

arch('no debugging calls are left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

/*
| A3 · another module is reachable only through its Contracts namespace.
| Built from the folders on disk, so a new module is guarded on the day it is added.
*/
$units = [];

foreach (['core' => 'Acadly\Core', 'modules' => 'Acadly\Modules'] as $folder => $namespace) {
    foreach (glob(dirname(__DIR__, 2)."/{$folder}/*", GLOB_ONLYDIR) ?: [] as $path) {
        $units[] = $namespace.'\\'.basename($path);
    }
}

foreach ($units as $unit) {
    foreach ($units as $other) {
        if ($unit === $other) {
            continue;
        }

        arch("A3 · {$unit} reaches {$other} only through its Contracts")
            ->expect($unit)
            ->not->toUse($other)
            ->ignoring([$other.'\Contracts', $other.'\\'.basename(str_replace('\\', '/', $other)).'Core', $other.'\\'.basename(str_replace('\\', '/', $other)).'Module']);
    }
}
