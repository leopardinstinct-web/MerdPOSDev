# MERDPOS Kimi MCP Bridge

Private MCP bridge for the MERDPOS dual-agent workflow:

`ChatGPT -> OpenAI Secure MCP Tunnel -> local bridge -> Kimi Code CLI -> local MERDPOS feature branch`

The bridge is intentionally local/private. It does not expose a public Kimi relay and does not store a Kimi API key. It uses the Kimi Code CLI login already present on the workstation.

## Security model

- `KIMI_API_KEY` is not required by the bridge when Kimi Code CLI is already authenticated with `kimi login`.
- `kimi_implement_task` runs Kimi with `merdpos-implementer.md`; Kimi receives only `Read`, `Grep`, `Glob`, `Write`, and `Edit` tools. It has no Bash/shell access.
- Kimi cannot push, merge, or deploy through its implementation tool.
- `repo_checkout_feature` only checks out an existing remote `feature/`, `fix/`, or `hotfix/` branch and refuses dirty working trees.
- `repo_commit_push` refuses `main`, `namecheap-beta-live`, and `beta/drupal-webapp`; it also rejects secret-like paths and requires `git diff --check` to pass before committing.
- The bridge never implements merge or deployment actions.

## Requirements

- Windows development workstation with the MERDPOS repository cloned locally.
- Python 3.10+.
- Kimi Code CLI installed and authenticated (`kimi login`).
- OpenAI Secure MCP Tunnel access for the ChatGPT workspace.
- `git` available on PATH.

## Install locally

From this directory:

```powershell
py -3 -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
```

Set the repository path for the bridge process. Example only; use the actual clone path:

```powershell
$env:MERDPOS_REPO_PATH = 'C:\Dev\MERDPOSDev-drupal-dev-v2'
```

Verify Kimi itself is ready:

```powershell
kimi --version
kimi login
```

Do not put credentials or Kimi/OpenAI keys in this repository.

## Local MCP test

The server uses MCP stdio transport:

```powershell
python .\server.py
```

For interactive inspection, use the current official MCP Inspector against the stdio command, or configure Secure MCP Tunnel directly to launch `server.py`.

## Secure MCP Tunnel

OpenAI's tunnel client runs locally and starts this stdio MCP server. Create a tunnel in OpenAI Platform tunnel settings first and obtain its `tunnel_id` and runtime API key.

Run the current `tunnel-client` release and initialize a profile similar to:

```powershell
$env:CONTROL_PLANE_API_KEY = '<OpenAI Platform runtime key>'
$env:MERDPOS_REPO_PATH = 'C:\Dev\MERDPOSDev-drupal-dev-v2'

tunnel-client init `
  --sample sample_mcp_stdio_local `
  --profile merdpos-kimi `
  --tunnel-id <tunnel_id> `
  --mcp-command 'C:\path\to\python.exe C:\path\to\MerdPOSDev\tools\kimi_mcp_bridge\server.py'

tunnel-client doctor --profile merdpos-kimi --explain
tunnel-client run --profile merdpos-kimi
```

Keep the tunnel client running while ChatGPT uses the MCP app. The OpenAI runtime key belongs only in the local environment/tunnel configuration, never in Git.

## Add to ChatGPT

In ChatGPT developer mode:

1. Plugins -> `+` -> **Create MCP App**.
2. Under Connection select **Tunnel**.
3. Choose the MERDPOS tunnel or paste its `tunnel_id`.
4. Scan tools and create the draft app.
5. Start a new ChatGPT conversation with the app enabled after changing MCP tool schemas.

Suggested app name: `MERDPOS Kimi Bridge`.

## Tools

### `bridge_status`
Read-only readiness check for the configured repo and Kimi CLI. It also reports the current/most-recent Kimi job state without exposing the task prompt. While a job is running it includes the task ID and elapsed time; after completion it retains the exit/result summary, changed paths, bounded assistant output and diagnostic so a client timeout cannot erase the outcome.

### `repo_checkout_feature`
Checks out an existing remote feature/fix/hotfix branch after ensuring the working tree is clean.

### `kimi_implement_task`
Submits one fresh, non-interactive Kimi Code job against the current feature branch and **returns immediately** with a task ID. The Kimi process runs on the bridge host in a background worker; poll `bridge_status` until `kimi_task.active=false`, then inspect the retained result and `repo_diff`. Defaults are tuned for quota efficiency:

- model: `kimi-code/k3-256k`
- effort: `low`
- Kimi output cap: 12,000 tokens per LLM step
- no web tools, no Bash, no sub-agents

Use `high` when the handoff still has real multi-file ambiguity. Reserve `max` for genuinely difficult/high-risk implementation reasoning.

Only one Kimi implementation task may run at a time. The bridge rejects concurrent invocations, branch checkout, and commit/push while the worker is active. Long Kimi runtime no longer keeps an MCP request open, so normal ChatGPT/tool request timeouts do not interrupt or ambiguously detach the job. `repo_diff` remains available while a job is running for read-only inspection.

### `repo_diff`
Returns bounded local `git status` and `git diff` output so ChatGPT can inspect the actual implementation before it is pushed.

### `repo_commit_push`
Commits and pushes the current working tree only from a feature/fix/hotfix branch. It intentionally does not merge or deploy.

## Operating sequence

1. ChatGPT creates or identifies the GitHub feature branch.
2. `repo_checkout_feature` checks out that exact remote branch locally.
3. ChatGPT sends a compact implementation contract through `kimi_implement_task` and receives an immediate task ID.
4. ChatGPT polls `bridge_status` until the task completes; the result remains available in `kimi_task`.
5. `repo_diff` exposes Kimi's actual edits for inspection.
6. ChatGPT may request another bounded Kimi edit if needed.
7. With owner approval as required by the active workflow, `repo_commit_push` pushes the immutable implementation SHA.
8. GitHub CI runs.
9. ChatGPT performs independent Git/CI review under `.ai/task-gates.md`.
10. Merge/deploy remain outside this bridge and continue to require the normal MERDPOS gates.

## Quota discipline

Kimi usage limits still apply. This bridge does not bypass membership quotas. Prefer `k3-256k` on Plus for bounded MERDPOS changes because it provides the same K3 behavior within 256K context while using less quota than the 1M K3 path. Start a fresh Kimi invocation for each bounded handoff instead of carrying long interactive history.
