# MERDPOS Beta — Resumable Work Packets

This directory stores compact mid-task execution state so implementation can continue reliably across long chats, context resets, tool changes and concurrent sessions without treating chat history as memory.

Work packets complement the durable `.ai` project brain. They do not replace current source, invariants, task gates, durable decisions, tests or deployment/runtime evidence.

## Layout

- `ACTIVE.yaml` — small index of packets that are currently resumable/in progress.
- `active/<task-id>.yaml` — one packet per active non-trivial task.
- `archive/<task-id>.yaml` — closed packet retained as execution provenance when useful.

## When a packet is required

Use a packet when any of these materially apply:

- the task spans multiple meaningful checkpoints;
- implementation crosses tools/environments such as GitHub → test runner → deployment → browser verification;
- the task is blocked and will resume later;
- the task is likely to continue in another chat/session;
- concurrent sessions could touch the same area;
- losing the current `next_action` would force expensive reconstruction.

A small isolated fix that is completed, checked and reported safely in one continuous turn does not need a packet.

## Packet checkpoint states

`OPEN → RECONNED → READY → EXECUTING → CHECKED → DEPLOYED → VERIFIED → CLOSED`

These are execution/checkpoint states, not a replacement for the project lifecycle:

`REQUESTED → DOCUMENTED → CODED → WIRED → DEPLOYED → VERIFIED`

A packet may be `CLOSED` while the project lifecycle is `WIRED` when deployment is intentionally not applicable or not requested. Never inflate lifecycle evidence just to match the packet state.

## Minimum schema

```yaml
version: 1
id: MERD-YYYYMMDD-short-name
objective: "One-sentence desired outcome"
acceptance:
  - "Observable acceptance criterion"
packet_state: READY
lifecycle: DOCUMENTED
base_head: "full starting canonical SHA"
last_seen_head: "full most recently reconciled canonical SHA"
affected_paths:
  - "path/or/component"
constraints:
  - "binding task-specific constraint"
completed:
  - "factual checkpoint/evidence already completed"
checks:
  - name: "exact check or command"
    result: "PASS | FAIL | NOT_RUN"
    evidence: "short factual result/reference"
remaining:
  - "unfinished requirement"
next_action: "Exactly one concrete next action"
blocked: false
blocker: null
updated_at: "ISO-8601 timestamp with offset when known"
```

Optional fields such as `commits`, `deployment_evidence`, `verification_evidence`, `related_packets`, or `notes` may be added when they reduce future reconstruction. Keep them factual and compact.

## Checkpoint discipline

Update a packet at meaningful boundaries, not after every tool call. Good checkpoint moments are:

- reconnaissance/history pass completed;
- implementation approach stabilized enough to execute;
- source implementation committed;
- targeted checks completed;
- deployment evidence obtained;
- runtime/visual verification completed;
- a genuine blocker discovered;
- scope or acceptance criteria materially changed.

A packet is not a narrative diary. Prefer a short `completed` list and one precise `next_action` over prose history.

## Concurrency / stale-head protocol

Each packet records `base_head` and `last_seen_head`.

Before editing an affected path when current `namecheap-beta-live` differs from `last_seen_head`:

1. inspect commits/diff from `last_seen_head` to current HEAD for packet-affected paths;
2. if there is no relevant overlap, set `last_seen_head` to current HEAD and continue;
3. if there is overlap, reread the current affected source and relevant history before editing;
4. revise stale completed/remaining assumptions when the newer source invalidates them;
5. if a newer durable decision conflicts with the packet, the newer decision/current source wins.

Packets are not locks. Two packets may legitimately touch related areas, but neither may blindly overwrite newer canonical source.

## Lean-context resume protocol

To resume work:

1. resolve current canonical branch HEAD;
2. read `AGENTS.md`, `.ai/README.md`, `.ai/invariants.md`, `.ai/task-gates.md` and `ACTIVE.yaml`;
3. read the relevant active packet;
4. load only the task-specific source/history and targeted durable knowledge needed for its `next_action`;
5. execute from the checkpoint instead of replaying the originating chat.

If the packet cannot answer “what exactly should happen next?” it is incomplete.

## Closure

When work is done:

- record final evidence and lifecycle state;
- set `packet_state: CLOSED`;
- promote durable lessons to curated project docs only when they deserve to outlive the task;
- remove the packet from `ACTIVE.yaml`;
- move it from `active/` to `archive/`.

Do not store credentials, cookies, browser storage state, private user data, secrets or sensitive logs in work packets.
