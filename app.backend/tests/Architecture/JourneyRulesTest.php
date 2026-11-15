<?php

declare(strict_types=1);

/*
| docs/architecture/02-blueprints.md §1 law 5: no conditionals inside a
| declaration, and no education level named in a step. Every journey
| declaration in the build, and the toy, is read here.
*/

$root = dirname(__DIR__, 2);
$declarations = [];

foreach (['core', 'modules', 'tests/Fixtures'] as $folder) {
    foreach (glob("{$root}/{$folder}/*/Journeys/*/V*.php") ?: [] as $path) {
        $declarations[] = str_replace('\\', '/', $path);
    }
}

it('finds the journey declarations to check', function () use ($declarations): void {
    expect($declarations)->not->toBeEmpty();
});

foreach ($declarations as $path) {
    $name = substr($path, strlen(str_replace('\\', '/', $root)) + 1);

    it("{$name} holds no conditional and names no level", function () use ($path): void {
        $code = (string) file_get_contents($path);

        // `if`, `match`, `switch`, and the ternary and null-coalescing operators.
        expect(preg_match('/\b(if|elseif|match|switch)\s*\(|\s\?\s|\s\?:|\?\?/', $code))->toBe(0)
            ->and(preg_match('/\b(Ecd|Primary|Secondary|Tertiary)\b/i', $code))->toBe(0);
    });
}
