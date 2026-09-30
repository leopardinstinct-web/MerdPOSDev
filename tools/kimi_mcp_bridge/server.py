from __future__ import annotations

import os
import re
import shutil
import subprocess
import time
from pathlib import Path
from typing import Literal

from mcp.server import MCPServer

SERVER_VERSION = "0.1.0"
FORBIDDEN_BRANCHES = {"main", "namecheap-beta-live", "beta/drupal-webapp"}
SAFE_BRANCH_PREFIXES = ("feature/", "fix/", "hotfix/")
FORBIDDEN_PATH_PATTERNS = (
    re.compile(r"(^|/)\.env($|\.)", re.I),
    re.compile(r"(^|/)\.tools/", re.I),
    re.compile(r"(^|/)(?:credentials?|secrets?)(?:\.|/|$)", re.I),
    re.compile(r"(^|/)(?:cookies?|storage-state)(?:\.|/|$)", re.I),
    re.compile(r"\.(?:pem|key|p12|pfx|jks|keystore)$", re.I),
    re.compile(r"(^|/)config\.php$", re.I),
)

mcp = MCPServer(
    "merdpos-kimi-bridge",
    version=SERVER_VERSION,
    instructions=(
        "Private MERDPOS bridge. Kimi may inspect/edit only the configured local MERDPOS repo. "
        "Kimi cannot run shell commands, push, merge, or deploy. Use separate repository tools for "
        "branch checkout, diff inspection, and feature-branch commit/push. Never send secrets."
    ),
)


def _repo_root() -> Path:
    raw = os.environ.get("MERDPOS_REPO_PATH", "").strip()
    if not raw:
        raise RuntimeError("MERDPOS_REPO_PATH is not configured on the bridge host.")
    root = Path(raw).expanduser().resolve()
    if not (root / ".git").exists():
        raise RuntimeError(f"MERDPOS_REPO_PATH is not a Git working tree: {root}")
    return root


def _resolve_kimi_cli() -> str:
    explicit = os.environ.get("KIMI_CLI_PATH", "").strip()
    candidates: list[str] = []
    if explicit:
        candidates.append(explicit)
    for name in ("kimi", "kimi.cmd"):
        resolved = shutil.which(name)
        if resolved:
            candidates.append(resolved)
    appdata = os.environ.get("APPDATA", "").strip()
    if appdata:
        candidates.append(str(Path(appdata) / "npm" / "kimi.cmd"))
    for candidate in candidates:
        if candidate and Path(candidate).exists():
            return str(Path(candidate).resolve())
    raise RuntimeError(
        "Kimi CLI was not found. Set KIMI_CLI_PATH or install @moonshot-ai/kimi-code and ensure its npm bin is available."
    )


def _run(args: list[str], *, timeout: int = 120, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        args,
        cwd=_repo_root(),
        env=env,
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
        shell=False,
    )


def _git(*args: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    return _run(["git", *args], timeout=timeout)


def _current_branch() -> str:
    result = _git("branch", "--show-current")
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "Unable to resolve current Git branch.")
    branch = result.stdout.strip()
    if not branch:
        raise RuntimeError("Detached HEAD is not allowed for bridge write operations.")
    return branch


def _assert_feature_branch(branch: str) -> None:
    if branch in FORBIDDEN_BRANCHES or not branch.startswith(SAFE_BRANCH_PREFIXES):
        raise RuntimeError(
            f"Refusing write operation on branch '{branch}'. Use a feature/fix/hotfix branch; "
            "canonical branches are protected."
        )


def _status_paths() -> list[str]:
    result = _git("status", "--porcelain=v1", "--untracked-files=all")
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "git status failed")
    paths: list[str] = []
    for line in result.stdout.splitlines():
        if not line.strip():
            continue
        payload = line[3:]
        if " -> " in payload:
            payload = payload.split(" -> ", 1)[1]
        paths.append(payload.strip().strip('"').replace("\\", "/"))
    return paths


def _assert_no_sensitive_paths(paths: list[str]) -> None:
    bad = [p for p in paths if any(rx.search(p) for rx in FORBIDDEN_PATH_PATTERNS)]
    if bad:
        raise RuntimeError("Refusing to stage sensitive/forbidden paths: " + ", ".join(sorted(bad)))


def _truncate(text: str, limit: int) -> str:
    if len(text) <= limit:
        return text
    half = max(1000, limit // 2)
    return text[:half] + f"\n... <truncated {len(text) - 2 * half} chars> ...\n" + text[-half:]


def _redact(text: str) -> str:
    text = re.sub(r"\bsk-[A-Za-z0-9_-]{12,}\b", "sk-<redacted>", text)
    text = re.sub(r"(?i)(api[_ -]?key|token|password)\s*[:=]\s*\S+", r"\1=<redacted>", text)
    return text


@mcp.tool()
def bridge_status() -> dict[str, object]:
    """Check local MERDPOS repo and Kimi CLI readiness without changing anything."""
    root = _repo_root()
    branch = _current_branch()
    try:
        kimi_cli = _resolve_kimi_cli()
        kimi = subprocess.run([kimi_cli, "--version"], text=True, capture_output=True, check=False, shell=False)
        kimi_available = kimi.returncode == 0
        kimi_version = (kimi.stdout or kimi.stderr).strip()[:500]
    except RuntimeError as exc:
        kimi_available = False
        kimi_version = str(exc)
    return {
        "bridge_version": SERVER_VERSION,
        "repo_path": str(root),
        "branch": branch,
        "working_tree_changes": len(_status_paths()),
        "kimi_cli_available": kimi_available,
        "kimi_cli_version": kimi_version,
    }


@mcp.tool()
def repo_checkout_feature(branch: str) -> dict[str, object]:
    """Checkout an existing remote feature/fix/hotfix branch. Refuses dirty trees and canonical branches."""
    _assert_feature_branch(branch)
    dirty = _status_paths()
    if dirty:
        raise RuntimeError("Working tree is not clean; refusing branch checkout.")
    fetch = _git("fetch", "origin", "--prune", timeout=300)
    if fetch.returncode != 0:
        raise RuntimeError(fetch.stderr.strip() or "git fetch failed")
    verify = _git("show-ref", "--verify", "--quiet", f"refs/remotes/origin/{branch}")
    if verify.returncode != 0:
        raise RuntimeError(f"Remote branch origin/{branch} does not exist. Create it in GitHub first.")
    checkout = _git("checkout", "-B", branch, f"origin/{branch}")
    if checkout.returncode != 0:
        raise RuntimeError(checkout.stderr.strip() or "git checkout failed")
    return {"branch": _current_branch(), "head": _git("rev-parse", "HEAD").stdout.strip()}


@mcp.tool()
def repo_diff(max_chars: int = 40000) -> dict[str, object]:
    """Return local MERDPOS Git status and diff, truncated to a bounded size."""
    max_chars = min(max(max_chars, 4000), 100000)
    status = _git("status", "--short")
    diff = _git("diff", "--no-ext-diff", "--unified=3", timeout=180)
    return {
        "branch": _current_branch(),
        "status": _truncate(status.stdout, 12000),
        "diff": _truncate(diff.stdout, max_chars),
    }


@mcp.tool()
def kimi_implement_task(
    task: str,
    model: Literal["default", "kimi-code/k3-256k", "kimi-code/k3", "kimi-code/kimi-for-coding"] = "kimi-code/k3-256k",
    effort: Literal["low", "high", "max"] = "low",
    timeout_seconds: int = 1800,
) -> dict[str, object]:
    """Run one bounded Kimi implementation turn against the current feature branch. Kimi may read/edit files but cannot use Bash, push, merge, or deploy."""
    if not task.strip():
        raise RuntimeError("Task is empty.")
    if len(task) > 30000:
        raise RuntimeError("Task is too large; keep the handoff under 30,000 characters.")
    branch = _current_branch()
    _assert_feature_branch(branch)
    timeout_seconds = min(max(timeout_seconds, 60), 3600)
    root = _repo_root()
    agent_file = Path(__file__).with_name("merdpos-implementer.md").resolve()
    if not agent_file.exists():
        raise RuntimeError(f"Missing bridge agent file: {agent_file}")

    env = os.environ.copy()
    env["KIMI_MODEL_THINKING_EFFORT"] = effort
    env.setdefault("KIMI_MODEL_MAX_COMPLETION_TOKENS", "12000")
    env.setdefault("KIMI_CODE_NO_AUTO_UPDATE", "1")

    wrapped = (
        "MERDPOS bridge task. Work only inside the current repository and current branch. "
        "Follow repository AGENTS.md and the supplied task. Be surgical: use targeted reads/searches, "
        "do not broadly scan the repository, do not touch secrets, and stop immediately after the requested "
        "file edits. You do not have shell access; do not claim tests, commits, pushes, merges, deployment, or "
        "runtime verification.\n\nTASK:\n" + task.strip()
    )

    kimi_cli = _resolve_kimi_cli()
    cmd = [kimi_cli, "--agent-file", str(agent_file)]
    if model != "default":
        cmd += ["-m", model]
    cmd += ["-p", wrapped]

    started = time.monotonic()
    try:
        result = subprocess.run(
            cmd,
            cwd=root,
            env=env,
            text=True,
            capture_output=True,
            timeout=timeout_seconds,
            check=False,
            shell=False,
        )
    except subprocess.TimeoutExpired as exc:
        return {
            "ok": False,
            "branch": branch,
            "timed_out": True,
            "elapsed_seconds": round(time.monotonic() - started, 1),
            "assistant_output": _truncate(exc.stdout or "", 16000),
            "diagnostic": "Kimi task timed out. Inspect repo_diff before deciding whether to retry.",
        }

    return {
        "ok": result.returncode == 0,
        "branch": branch,
        "model": model,
        "effort": effort,
        "exit_code": result.returncode,
        "elapsed_seconds": round(time.monotonic() - started, 1),
        "assistant_output": _truncate(result.stdout, 24000),
        "diagnostic": "" if result.returncode == 0 else _truncate(_redact(result.stderr), 8000),
        "changed_paths": _status_paths(),
    }


@mcp.tool()
def repo_commit_push(expected_branch: str, message: str) -> dict[str, object]:
    """Commit and push the current local changes on the named feature/fix/hotfix branch. Refuses canonical branches, sensitive paths, empty diffs, and whitespace errors."""
    branch = _current_branch()
    if branch != expected_branch:
        raise RuntimeError(f"Current branch is '{branch}', not expected '{expected_branch}'.")
    _assert_feature_branch(branch)
    if not message.strip() or len(message) > 160:
        raise RuntimeError("Commit message must be 1-160 characters.")
    paths = _status_paths()
    if not paths:
        raise RuntimeError("No local changes to commit.")
    _assert_no_sensitive_paths(paths)

    check = _git("diff", "--check", timeout=180)
    if check.returncode != 0:
        raise RuntimeError("git diff --check failed:\n" + _truncate(check.stdout + check.stderr, 8000))

    add = _git("add", "-A")
    if add.returncode != 0:
        raise RuntimeError(add.stderr.strip() or "git add failed")
    staged_check = _git("diff", "--cached", "--check", timeout=180)
    if staged_check.returncode != 0:
        _git("reset")
        raise RuntimeError("staged diff check failed:\n" + _truncate(staged_check.stdout + staged_check.stderr, 8000))

    commit = _git("commit", "-m", message, timeout=300)
    if commit.returncode != 0:
        raise RuntimeError(commit.stderr.strip() or commit.stdout.strip() or "git commit failed")
    sha = _git("rev-parse", "HEAD").stdout.strip()
    push = _git("push", "origin", "HEAD", timeout=300)
    if push.returncode != 0:
        raise RuntimeError(
            f"Commit {sha} was created locally but push failed: " + _truncate(push.stderr, 8000)
        )
    return {"branch": branch, "commit_sha": sha, "changed_paths": paths, "push": "success"}


if __name__ == "__main__":
    mcp.run(transport="stdio")
