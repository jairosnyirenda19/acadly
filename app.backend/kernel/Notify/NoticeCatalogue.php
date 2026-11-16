<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Blueprint\Canonical;
use Acadly\Kernel\Documents\Topics\StorageFilling;
use Acadly\Kernel\Grid\ExportReady;
use Acadly\Kernel\Module\ModuleRegistry;
use Acadly\Kernel\Notify\Topics\DeliveriesWaiting;
use LogicException;

/**
 * Every notice in the build: the kernel's own, then each module's, from its
 * manifest (04 §2). The settings and wording pages read this, so neither can
 * offer a message that nothing sends.
 *
 * Rules checked here: a key has the form `<owner>.<event>`, starts with the
 * key of the module that declares it, and is unique. What the words mean is
 * checked by `NoticeLinter`.
 */
final class NoticeCatalogue
{
    /** @var array<class-string<Topic>, string> the kernel's own topics, with the area that owns each */
    public const array KERNEL_TOPICS = [DeliveriesWaiting::class => 'notify', StorageFilling::class => 'documents', ExportReady::class => 'notify'];

    /** @var array<string, class-string<Topic>> */
    private array $topics = [];

    /** @var array<string, Notice> */
    private array $notices = [];

    /** @var array<string, string> key => owner */
    private array $owners = [];

    public function __construct(ModuleRegistry $modules)
    {
        foreach (self::KERNEL_TOPICS as $topic => $owner) {
            $this->add($owner, $topic);
        }

        foreach ($modules->all() as $module) {
            foreach ($module->topics() as $topic) {
                $this->add($module->key(), $topic);
            }
        }
    }

    public function has(string $key): bool
    {
        return isset($this->topics[$key]);
    }

    public function notice(string $key): Notice
    {
        return $this->notices[$key] ?? throw new LogicException("[{$key}] is not a notice in this build.");
    }

    /** @return class-string<Topic> */
    public function topic(string $key): string
    {
        return $this->topics[$key] ?? throw new LogicException("[{$key}] is not a notice in this build.");
    }

    public function ownerOf(string $key): string
    {
        return $this->owners[$key] ?? throw new LogicException("[{$key}] is not a notice in this build.");
    }

    /** @return array<string, Notice> */
    public function all(): array
    {
        return $this->notices;
    }

    /** The hash of the compiled catalogue. The readiness probe compares it with the last sync (04 §9). */
    public function hash(): string
    {
        $all = array_map(fn (Notice $notice): array => $notice->toArray(), $this->notices);
        ksort($all, SORT_STRING);

        return Canonical::hash(array_values($all));
    }

    /** @param  class-string<Topic>  $topic */
    private function add(string $owner, string $topic): void
    {
        $notice = $topic::notice();
        $key = $notice->key;

        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new LogicException("Notice key [{$key}] must have the form owner.event.");
        }

        if (! str_starts_with($key, $owner.'.')) {
            throw new LogicException("Notice [{$key}] is declared by [{$owner}] and must start with [{$owner}.].");
        }

        if (isset($this->topics[$key])) {
            throw new LogicException("Notice [{$key}] is declared twice.");
        }

        $this->topics[$key] = $topic;
        $this->notices[$key] = $notice;
        $this->owners[$key] = $owner;
    }
}
