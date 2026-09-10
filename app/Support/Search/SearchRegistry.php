<?php

namespace App\Support\Search;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A30 global search. Each module registers a searcher in its ServiceProvider::boot():
 *
 *     $this->app->make(SearchRegistry::class)->register('orders', fn (string $q): array => [...]);
 *
 * A searcher returns hits: ['type' => 'order', 'label' => 'ORD-…', 'url' => route(...), 'meta' => 'client · status'].
 * Keep it cheap (indexed columns, limit 20) and respect the global client scope (client users search only their rows).
 *
 * `$roles` (audit 2026-09-10): the roles that may open the URLs the searcher emits — the same list as the module's route
 * middleware. Searchers with a role list are skipped for users outside it, so the search page never offers a hit that 403s.
 */
final class SearchRegistry
{
    /** @var array<string, array{searcher: callable(string): list<array{type:string, label:string, url:string, meta?:string}>, roles: list<string>}> */
    private array $searchers = [];

    /** @param list<string> $roles empty = every user who can open the search page */
    public function register(string $module, callable $searcher, array $roles = []): void
    {
        $this->searchers[$module] = ['searcher' => $searcher, 'roles' => $roles];
    }

    /**
     * Google-like: the whole phrase first, then every keyword on its own (any order, case-insensitive — the DB collation
     * is case-insensitive); hits are merged per module by URL and ranked by how many keywords appear in label + meta.
     * "20260908 0008" therefore finds ORD-20260908-0008 even though the user typed no dashes.
     *
     * @return array<string, list<array{type:string, label:string, url:string, meta?:string}>> module → hits
     */
    public function search(string $query, ?Authenticatable $user = null): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 2) {
            return [];
        }
        $user ??= auth()->user();
        $tokens = array_values(array_unique(array_filter(explode(' ', $query), fn ($t) => mb_strlen($t) >= 2)));
        $terms = array_values(array_unique(array_merge([$query], count($tokens) > 1 ? $tokens : [])));

        $results = [];
        foreach ($this->searchers as $module => ['searcher' => $searcher, 'roles' => $roles]) {
            if ($roles !== [] && ! ($user !== null && method_exists($user, 'hasAnyRole') && $user->hasAnyRole($roles))) {
                continue;
            }
            $hits = [];
            foreach ($terms as $term) {
                foreach ($searcher($term) as $hit) {
                    $hits[$hit['url']] ??= $hit;
                }
            }
            if ($hits === []) {
                continue;
            }
            $ranked = array_values($hits);
            usort($ranked, fn ($a, $b) => $this->score($b, $tokens ?: [$query]) <=> $this->score($a, $tokens ?: [$query]));
            $results[$module] = array_slice($ranked, 0, 20);
        }

        return $results;
    }

    /** @param array{label:string, meta?:string} $hit */
    private function score(array $hit, array $tokens): int
    {
        $haystack = mb_strtolower($hit['label'].' '.($hit['meta'] ?? ''));
        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, mb_strtolower($token))) {
                $score++;
            }
        }

        return $score;
    }
}
