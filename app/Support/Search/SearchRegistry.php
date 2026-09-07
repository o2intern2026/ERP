<?php

namespace App\Support\Search;

/**
 * A30 global search. Each module registers a searcher in its ServiceProvider::boot():
 *
 *     $this->app->make(SearchRegistry::class)->register('orders', fn (string $q): array => [...]);
 *
 * A searcher returns hits: ['type' => 'order', 'label' => 'ORD-…', 'url' => route(...), 'meta' => 'client · status'].
 * Keep it cheap (indexed columns, limit 20) and respect the global client scope (client users search only their rows).
 */
final class SearchRegistry
{
    /** @var array<string, callable(string): list<array{type:string, label:string, url:string, meta?:string}>> */
    private array $searchers = [];

    public function register(string $module, callable $searcher): void
    {
        $this->searchers[$module] = $searcher;
    }

    /** @return array<string, list<array{type:string, label:string, url:string, meta?:string}>> module → hits */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $results = [];
        foreach ($this->searchers as $module => $searcher) {
            $hits = $searcher($query);
            if ($hits !== []) {
                $results[$module] = $hits;
            }
        }

        return $results;
    }
}
