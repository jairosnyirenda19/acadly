<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Console;

use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\Projection;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Prints the Mermaid state diagram of a compiled journey (02 §3.7). */
final class BlueprintDiagramCommand extends Command
{
    protected $signature = 'blueprint:diagram {ref : KEY@VERSION}';

    protected $description = 'Print the state diagram of a journey';

    public function handle(Blueprints $blueprints, Projection $projection): int
    {
        try {
            $ref = BlueprintRef::parse((string) $this->argument('ref'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $blueprints->has($ref)) {
            $this->components->error("[{$ref}] is not compiled in this build.");

            return self::FAILURE;
        }

        $this->output->write($projection->diagram($blueprints->journey($ref)));

        return self::SUCCESS;
    }
}
