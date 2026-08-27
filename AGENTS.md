# MERDPOS Beta Feature-Branch Entry Guard

This branch is not authoritative MERDPOS Beta source.

Before any further Beta planning or implementation on this branch:

1. Resolve the current `namecheap-beta-live` HEAD from GitHub.
2. Read `AGENTS.md`, `.ai/README.md`, `.ai/invariants.md`, `.ai/task-gates.md`, `.ai/memory.md`, `.ai/decisions.md` and `.ai/playbook.md` from that authoritative ref.
3. Compare this branch with current `namecheap-beta-live` and reconcile/recreate it before substantive work if it is stale or diverged.
4. Do not treat this branch's absence of newer bootstrap files as permission to bypass the authoritative Beta operating contract.
5. Small bounded Beta changes should normally be implemented directly on `namecheap-beta-live` unless isolation is specifically justified.

This guard was added after this branch demonstrated the stale-branch bootstrap failure mode. Product changes on this branch remain non-authoritative until deliberately reconciled with current Beta.
