# MERDPOS Repository Entry Guard

This repository contains multiple product lines and branches. Do not assume the default branch is authoritative for every task.

## MERDPOS Beta tasks

If the task is about the MERDPOS Beta web portal, `app.merdpos.com/beta`, the Namecheap Beta deployment, or a chat/project identified as MERDPOS BETA:

1. Resolve the current GitHub HEAD of `namecheap-beta-live` explicitly.
2. Read `AGENTS.md` from `namecheap-beta-live`, then follow its `.ai/` bootstrap sequence from that same branch.
3. Ignore any Beta bootstrap copied from `main`, an old feature branch, a prior checkout, or chat state inherited before the session entered the MERDPOS BETA project.
4. If the session/chat was moved into the MERDPOS BETA project, re-bootstrap before the next substantive plan or implementation.
5. Before using an existing Beta feature branch, compare it with the current `namecheap-beta-live` HEAD. Do not continue substantive work on a stale/diverged branch; reconcile/recreate it first.
6. For small bounded Beta changes, prefer direct work on `namecheap-beta-live` unless there is a concrete reason to isolate the work.

`main` is only a discovery point for Beta. It is not the Beta source of truth.

For non-Beta work, follow the branch and product-specific documentation applicable to that task.
