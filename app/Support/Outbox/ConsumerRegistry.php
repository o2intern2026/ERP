<?php

namespace App\Support\Outbox;

/** event name → consumer classes. One registry per application, filled by module providers at boot. */
final class ConsumerRegistry
{
    /** @var array<string, list<class-string<EventConsumer>>> */
    private array $consumers = [];

    /** @param class-string<EventConsumer> $consumerClass */
    public function register(string $eventName, string $consumerClass): void
    {
        if (! in_array($consumerClass, $this->consumers[$eventName] ?? [], true)) {
            $this->consumers[$eventName][] = $consumerClass;
        }
    }

    /** @return list<class-string<EventConsumer>> */
    public function for(string $eventName): array
    {
        return $this->consumers[$eventName] ?? [];
    }

    /** @return array<string, list<class-string<EventConsumer>>> */
    public function all(): array
    {
        return $this->consumers;
    }
}
