<?php

namespace App\Support\Outbox;

/**
 * event name → consumer classes. One registry per application, filled by module providers at boot.
 * Register under '*' to receive every event (used by the webhook pusher, A23).
 */
final class ConsumerRegistry
{
    public const WILDCARD = '*';

    /** @var array<string, list<class-string<EventConsumer>>> */
    private array $consumers = [];

    /** @param class-string<EventConsumer> $consumerClass */
    public function register(string $eventName, string $consumerClass): void
    {
        if (! in_array($consumerClass, $this->consumers[$eventName] ?? [], true)) {
            $this->consumers[$eventName][] = $consumerClass;
        }
    }

    /** @return list<class-string<EventConsumer>> named consumers first, then wildcard consumers */
    public function for(string $eventName): array
    {
        return array_values(array_unique(array_merge($this->consumers[$eventName] ?? [], $this->consumers[self::WILDCARD] ?? [])));
    }

    /** @return array<string, list<class-string<EventConsumer>>> */
    public function all(): array
    {
        return $this->consumers;
    }
}
