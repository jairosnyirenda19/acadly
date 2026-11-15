<?php

declare(strict_types=1);

namespace Acadly\Kernel\Console;

use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Acadly\Kernel\Module\ModuleRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The one command that runs every structural check
 * (docs/architecture/07-engineering-rules.md). It grows with each stage;
 * it checks the module graph, strict types, that the kernel names no
 * domain and no education level, and the blueprints.
 */
final class LintCommand extends Command
{
    protected $signature = 'acadly:lint';

    protected $description = 'Check the structural rules of the Acadly code base';

    /** Words that must never appear in kernel code (rule A2). */
    private const array KERNEL_FORBIDDEN = [
        'Learner', 'Guardian', 'Attendance', 'Admission', 'Assessment', 'Library',
        'Ecd', 'Primary', 'Secondary', 'Tertiary',
    ];

    public function handle(): int
    {
        $problems = [
            ...$this->moduleGraph(),
            ...$this->strictTypes(),
            ...$this->kernelNamesNoDomain(),
            ...$this->blueprints(),
        ];

        if ($problems === []) {
            $this->components->info('Acadly lint: no problems found.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        $this->components->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }

    /** @return list<string> */
    private function moduleGraph(): array
    {
        try {
            $modules = app(ModuleRegistry::class)->all();
        } catch (Throwable $e) {
            return ['Module graph: '.$e->getMessage()];
        }

        return $modules === [] ? ['Module graph: no module is registered in config/acadly.php.'] : [];
    }

    /** @return list<string> */
    private function blueprints(): array
    {
        try {
            $compiler = app(BlueprintCompiler::class);
        } catch (Throwable $e) {
            return ['Blueprints: '.$e->getMessage()];
        }

        return array_map(
            fn (string $problem): string => 'Blueprints: '.$problem,
            [...$compiler->problems(), ...$compiler->staleFiles(config()->string('acadly.blueprints.compiled'))],
        );
    }

    /** @return list<string> */
    private function strictTypes(): array
    {
        $problems = [];

        foreach ($this->sources(['kernel', 'core', 'modules']) as $path => $code) {
            if (! str_contains($code, 'declare(strict_types=1);')) {
                $problems[] = "{$path}: declare(strict_types=1) is missing.";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function kernelNamesNoDomain(): array
    {
        $problems = [];
        $pattern = '/\b('.implode('|', self::KERNEL_FORBIDDEN).')[A-Za-z]*\b/';

        foreach ($this->sources(['kernel']) as $path => $code) {
            if (str_ends_with($path, 'LintCommand.php')) {
                continue; // The list of forbidden words lives here.
            }

            if (preg_match($pattern, $code, $found) === 1) {
                $problems[] = "{$path}: the kernel names a domain or a level ({$found[0]}). Move it to a module or a blueprint.";
            }
        }

        return $problems;
    }

    /**
     * @param  list<string>  $folders
     * @return array<string, string> relative path => contents
     */
    private function sources(array $folders): array
    {
        $out = [];
        $present = array_values(array_filter(
            array_map(fn (string $folder): string => base_path($folder), $folders),
            'is_dir',
        ));

        if ($present === []) {
            return $out;
        }

        foreach (Finder::create()->files()->in($present)->name('*.php') as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $out[$relative] = $file->getContents();
        }

        return $out;
    }
}
