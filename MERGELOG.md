# Merge log

Written by seat C at every checkpoint merge (COLLAB_PLAN.md §6).

| Checkpoint | Date | Block branch | Tables added | Events added | Contract changes | Tests | Rebase needed by |
|---|---|---|---|---|---|---|---|
| M0 | | block/c0-bootstrap | users, password_reset_tokens, sessions, cache, cache_locks, queue_jobs (Laravel queue table, renamed from `jobs`), job_batches, failed_jobs — framework only | — (20 events defined in contracts/events.md, none emitted yet) | initial contracts/ (8 files); CHANGE_REQUESTS #1–#16 | 23 passed (104 assertions) | @X1 @X2 rebase `main` after merge |
