<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Console;

use Acadly\Kernel\Blueprint\BlueprintCompiler;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Support\Clock;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Freezes one blueprint version: its hash goes into the lock, and from then
 * on the version cannot change (02 §1 law 2). A change is a new version.
 */
final class BlueprintReleaseCommand extends Command
{
    protected $signature = 'blueprint:release {ref : KEY@VERSION}';

    protected $description = 'Release a blueprint version and lock its hash';

    public function handle(BlueprintCompiler $compiler, Clock $clock): int
    {
        try {
            $ref = BlueprintRef::parse((string) $this->argument('ref'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $problems = [...$compiler->problems(), ...$compiler->staleFiles(config()->string('acadly.blueprints.compiled'))];

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            $this->components->error('Resolve the problems, then release.');

            return self::FAILURE;
        }

        foreach ($compiler->compiled() as $journey) {
            if (! $journey->ref->equals($ref)) {
                continue;
            }

            if ($compiler->lock()->isReleased($ref)) {
                $this->components->error("[{$ref}] is already released.");

                return self::FAILURE;
            }

            $compiler->lock()->release($ref, $journey->hash(), $clock->now()->utc()->toIso8601String());
            $this->components->info("[{$ref}] is released. It can no longer change.");

            return self::SUCCESS;
        }

        $this->components->error("[{$ref}] is not declared in this build.");

        return self::FAILURE;
    }
}
