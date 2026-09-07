@AGENTS.md

# Claude-only notes (seat C)

- You are normally seat C (confirm via `CLAUDE.local.md` or `~/.claude/CLAUDE.md`). The rules above apply to you exactly as to the Codex seats; the extras below are yours alone.
- **Checkpoint merges** use the `integrator` sub-agent in `.claude/agents/integrator.md` (see `COLLAB_PLAN.md` §6): fetch the PR branch, confirm CI is green, merge into `main`, `migrate:fresh --seed`, `php artisan test`, `pint --test`, ownership check against `COLLAB_PLAN.md` §1.2, tag `M<n>`, append to `MERGELOG.md`, @-mention the other two seats to rebase.
- **Weekly drift check**: read-only pass over `block/x1-*` and `block/x2-*` comparing enums, event payloads and service signatures with `contracts/`. Report in `MERGELOG.md`; never edit their code outside a registered `HANDOFF.md` cover.
- **Contract changes**: process `contracts/CHANGE_REQUESTS.md` at each checkpoint; you are the only seat that edits `contracts/` and the frozen zone.
- **Covering for a Codex seat** (quota exhausted): register in `HANDOFF.md`, work on *their* block branch under *their* directory rules, hand back with a note.
