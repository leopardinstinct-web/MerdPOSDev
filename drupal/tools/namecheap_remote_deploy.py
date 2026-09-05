#!/usr/bin/env python3
"""MERDPOS Namecheap remote deployment helper.

Uses the ignored local Paramiko RSA identity to execute the canonical
server-side Git pull + deploy scripts. It never uploads source files or
stores credentials in Git.
"""
from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

def toolchain_candidates() -> list[Path]:
    candidates: list[Path] = []
    override = os.environ.get("MERDPOS_LOCAL_TOOLCHAIN", "").strip()
    if override:
        candidates.append(Path(override))
    candidates.append(REPO_ROOT / ".tools")
    candidates.append(REPO_ROOT.parent / "MerdPOSDev-drupal" / ".tools")
    return candidates

TOOLCHAIN = next((path for path in toolchain_candidates() if path.is_dir()), REPO_ROOT / ".tools")
LOCAL_PYDEPS = TOOLCHAIN / "pydeps"
if LOCAL_PYDEPS.is_dir():
    sys.path.insert(0, str(LOCAL_PYDEPS))

try:
    import paramiko  # type: ignore
except ModuleNotFoundError as exc:
    raise SystemExit(
        f"Paramiko is unavailable. Expected local toolchain at {TOOLCHAIN} or set MERDPOS_LOCAL_TOOLCHAIN."
    ) from exc

HOST = "198.187.29.30"
PORT = 21098
USER = "dridsheikh"
DEFAULT_KEY = TOOLCHAIN / "namecheap_drupal_deploy"

BACKEND_DIR = "/home/dridsheikh/git/MerdPOSDev-beta-mirror"
BACKEND_BRANCH = "namecheap-beta-live"
BACKEND_DEPLOY = "/bin/bash scripts/deploy_namecheap_beta.sh"

DRUPAL_DIR = "/home/dridsheikh/merdpos-drupal"
DRUPAL_BRANCH = "beta/drupal-webapp"
DRUPAL_DEPLOY = "/bin/bash drupal/tools/namecheap_deploy.sh"

def connect(key_path: Path):
    if not key_path.is_file():
        raise SystemExit(f"Deployment key not found: {key_path}")
    key = paramiko.RSAKey.from_private_key_file(str(key_path))
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        HOST,
        port=PORT,
        username=USER,
        pkey=key,
        look_for_keys=False,
        allow_agent=False,
        timeout=10,
    )
    print("SSH_OK")
    return client


def run(client, command: str, timeout: int = 1800) -> None:
    _, stdout, stderr = client.exec_command(command, timeout=timeout)
    for raw in iter(stdout.readline, ""):
        print(raw, end="")
    err = stderr.read().decode("utf-8", "replace")
    if err:
        print(err, file=sys.stderr, end="")
    status = stdout.channel.recv_exit_status()
    if status != 0:
        raise SystemExit(f"Remote command failed with exit code {status}.")


def preflight(client) -> None:
    run(client, f"""set -e
printf 'HOST='; hostname
printf 'BACKEND_HEAD='; git -C {BACKEND_DIR} rev-parse --short HEAD
printf 'BACKEND_BRANCH='; git -C {BACKEND_DIR} branch --show-current
printf 'DRUPAL_HEAD='; git -C {DRUPAL_DIR} rev-parse --short HEAD
printf 'DRUPAL_BRANCH='; git -C {DRUPAL_DIR} branch --show-current
""", timeout=30)


def deploy_backend(client) -> None:
    run(client, f"""set -e
cd {BACKEND_DIR}
git fetch origin {BACKEND_BRANCH}
git checkout {BACKEND_BRANCH}
git merge --ff-only origin/{BACKEND_BRANCH}
{BACKEND_DEPLOY}
""")


def deploy_drupal(client) -> None:
    run(client, f"""set -e
cd {DRUPAL_DIR}
git fetch origin {DRUPAL_BRANCH}
git checkout {DRUPAL_BRANCH}
git merge --ff-only origin/{DRUPAL_BRANCH}
{DRUPAL_DEPLOY}
""")


def main() -> None:
    parser = argparse.ArgumentParser(description="MERDPOS Namecheap remote deploy")
    parser.add_argument("action", choices=["preflight", "backend", "drupal", "all"])
    parser.add_argument(
        "--key",
        type=Path,
        default=Path(os.environ.get("MERDPOS_NAMECHEAP_KEY", DEFAULT_KEY)),
        help="ignored local RSA private key path",
    )
    args = parser.parse_args()
    client = connect(args.key)
    try:
        preflight(client)
        if args.action in {"backend", "all"}:
            deploy_backend(client)
        if args.action in {"drupal", "all"}:
            deploy_drupal(client)
    finally:
        client.close()


if __name__ == "__main__":
    main()
