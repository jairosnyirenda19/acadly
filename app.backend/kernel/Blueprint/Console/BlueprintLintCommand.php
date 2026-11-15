<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Console;

use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Illuminate\Console\Command;

/**
 * Checks every blueprint: reachability, exits, references to real gates,
 * effects, permissions and events, the lock, and that the compiled files
 * match their declarations (02 §3.9).
 */
final class BlueprintLintCommand extends Command
{
    protected $signature = 'blueprint:lint';

    protected $description = 'Check the blueprints of the build';

    public function handle(BlueprintCompiler $compiler): int
    {
        $problems = [...$compiler->problems(), ...$compiler->staleFiles(config()->string('acadly.blueprints.compiled'))];

        if ($problems === []) {
            $this->components->info('Blueprints: no problems found in '.count($compiler->compiled()).' version(s).');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
