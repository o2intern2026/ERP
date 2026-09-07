# Merge log

Written by seat C at every checkpoint merge (COLLAB_PLAN.md §6).

| Checkpoint | Date | Block branch | Tables added | Events added | Contract changes | Tests | Rebase needed by |
|---|---|---|---|---|---|---|---|
| M0 | 2026-09-07 | block/c0-bootstrap | users, password_reset_tokens, sessions, cache, cache_locks, queue_jobs (Laravel queue table, renamed from `jobs`), job_batches, failed_jobs — framework only | — (20 events defined in contracts/events.md, none emitted yet) | initial contracts/ (8 files); CHANGE_REQUESTS #1–#16 | 23 passed (104 assertions) | @X1 @X2 rebase `main` after merge |
| M1 | | block/c1-platform | clients, suppliers, carriers; roles, permissions, model_has_roles, model_has_permissions, role_has_permissions; users +client_id/is_active/last_login_at; jobs, exceptions, documents, outbox_events, consumed_events | — (outbox + consumer API live; no business events emitted yet) | services.md: JobService real, ExceptionService::hasActiveHold; events.md: consumer API; CHANGE_REQUESTS #17–#21 | 60 passed | @X1 @X2 rebase `main` after merge, then start block/x1-oms-min and block/x2-tms |
