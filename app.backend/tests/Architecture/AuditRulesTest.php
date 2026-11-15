<?php

declare(strict_types=1);

/*
| docs/architecture/10-audit-trail.md §7: every Action writes an audit line,
| or says why it does not with #[NotAudited('why')].
*/

$root = dirname(__DIR__, 2);
$actions = [];

foreach (['kernel', 'core', 'modules'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', (string) $file);

        if (str_ends_with($path, '.php') && str_contains($path, '/Actions/')) {
            $actions[] = $path;
        }
    }
}

it('finds the Actions to check', function () use ($actions): void {
    expect($actions)->not->toBeEmpty();
});

foreach ($actions as $path) {
    $name = substr($path, strlen(str_replace('\\', '/', $root)) + 1);

    it("{$name} writes an audit line or says why not", function () use ($path): void {
        $code = (string) file_get_contents($path);

        expect(str_contains($code, '->record(') || str_contains($code, '#[NotAudited('))->toBeTrue();
    });
}
