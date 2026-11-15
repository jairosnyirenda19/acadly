<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Console;

use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Illuminate\Console\Command;

/** Compiles every journey declaration to canonical JSON (02 §3.9). */
final class BlueprintCompileCommand extends Command
{
    protected $signature = 'blueprint:compile';

    protected $description = 'Compile the blueprint declarations to their canonical form';

    public function handle(BlueprintCompiler $compiler): int
    {
        if ($compiler->problems() !== []) {
            foreach ($compiler->problems() as $problem) {
                $this->components->error($problem);
            }

            $this->components->error('Nothing was written.');

            return self::FAILURE;
        }

        $written = $compiler->write(config()->string('acadly.blueprints.compiled'));

        foreach ($written as $ref) {
            $this->components->twoColumnDetail($ref, 'written');
        }

        $this->components->info(count($compiler->compiled()).' blueprint version(s) compiled, '.count($written).' file(s) changed.');

        return self::SUCCESS;
    }
}
