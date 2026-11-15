<?php

declare(strict_types=1);

namespace Acadly\Kernel\Events;

use Acadly\Kernel\Access\Events\RoleAssigned;
use Acadly\Kernel\Access\Events\RoleEnded;
use Acadly\Kernel\Module\ModuleRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Every domain event in the build and who consumes it. Built from the kernel's
 * own events and each manifest, and checked when it is built.
 *
 * Rules: an event name has the form `<owner>.<what_happened>` and starts with
 * the key of the module that declares it; names and consumer keys are unique;
 * a module subscribes only to events that exist in the build.
 */
final class EventCatalogue
{
    /** @var list<class-string<DomainEvent>> */
    public const array KERNEL_EVENTS = [RoleAssigned::class, RoleEnded::class];

    public const array KERNEL_OWNERS = ['identity', 'access', 'audit'];

    /** @var array<string, class-string<DomainEvent>> */
    private array $events = [];

    /** @var array<string, class-string<EventConsumer>> */
    private array $consumers = [];

    /** @var array<string, list<string>> event name => consumer keys */
    private array $subscriptions = [];

    public function __construct(ModuleRegistry $modules)
    {
        foreach (self::KERNEL_EVENTS as $event) {
            $this->addEvent(explode('.', $event::eventName())[0], $event, self::KERNEL_OWNERS);
        }

        foreach ($modules->all() as $module) {
            foreach ($module->events() as $event) {
                $this->addEvent($module->key(), $event, [$module->key()]);
            }
        }

        foreach ($modules->all() as $module) {
            foreach ($module->subscriptions() as $event => $consumers) {
                $name = $event::eventName();

                if (! isset($this->events[$name])) {
                    throw new LogicException("[{$module->key()}] subscribes to [{$name}], which is not an event in this build.");
                }

                foreach ($consumers as $consumer) {
                    $this->addConsumer($name, $consumer);
                }
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->events[$name]);
    }

    /** @param  array<string, mixed>  $payload */
    public function hydrate(string $name, array $payload): DomainEvent
    {
        $class = $this->events[$name] ?? throw new LogicException("[{$name}] is not an event in this build.");

        return $class::fromPayload($payload);
    }

    /** @return list<string> */
    public function consumersOf(string $name): array
    {
        return $this->subscriptions[$name] ?? [];
    }

    /** @return class-string<EventConsumer> */
    public function consumer(string $key): string
    {
        return $this->consumers[$key] ?? throw new LogicException("[{$key}] is not an event consumer in this build.");
    }

    public function makeConsumer(string $key, Container $container): EventConsumer
    {
        $consumer = $container->make($this->consumer($key));

        if (! $consumer instanceof EventConsumer) {
            throw new LogicException("[{$key}] does not resolve to an event consumer.");
        }

        return $consumer;
    }

    /**
     * @param  class-string<DomainEvent>  $event
     * @param  list<string>  $allowedOwners
     */
    private function addEvent(string $owner, string $event, array $allowedOwners): void
    {
        $name = $event::eventName();

        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new LogicException("Event name [{$name}] must have the form owner.what_happened.");
        }

        if (! in_array($owner, $allowedOwners, true) || ! str_starts_with($name, $owner.'.')) {
            throw new LogicException("Event [{$name}] must start with the key of the module that declares it.");
        }

        if (isset($this->events[$name])) {
            throw new LogicException("Event [{$name}] is declared twice.");
        }

        $this->events[$name] = $event;
    }

    /** @param  class-string<EventConsumer>  $consumer */
    private function addConsumer(string $event, string $consumer): void
    {
        $key = $consumer::consumerKey();

        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new LogicException("Consumer key [{$key}] must have the form owner.purpose.");
        }

        if (isset($this->consumers[$key]) && $this->consumers[$key] !== $consumer) {
            throw new LogicException("Consumer key [{$key}] is used by two consumers.");
        }

        $this->consumers[$key] = $consumer;

        if (! in_array($key, $this->subscriptions[$event] ?? [], true)) {
            $this->subscriptions[$event][] = $key;
        }
    }
}
