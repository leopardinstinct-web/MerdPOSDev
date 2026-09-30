import importlib.util
import sys
import types
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch


class DummyMCPServer:
    def __init__(self, *args, **kwargs):
        pass

    def tool(self):
        return lambda fn: fn

    def run(self, **kwargs):
        return None


fake_server = types.ModuleType("mcp.server")
fake_server.MCPServer = DummyMCPServer
fake_mcp = types.ModuleType("mcp")
fake_mcp.server = fake_server
sys.modules.setdefault("mcp", fake_mcp)
sys.modules.setdefault("mcp.server", fake_server)

spec = importlib.util.spec_from_file_location("bridge_server", Path(__file__).with_name("server.py"))
bridge = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(bridge)


class GuardTests(unittest.TestCase):
    def test_canonical_branches_are_rejected(self):
        for branch in ("main", "namecheap-beta-live", "beta/drupal-webapp"):
            with self.assertRaises(RuntimeError):
                bridge._assert_feature_branch(branch)

    def test_feature_branches_are_allowed(self):
        for branch in ("feature/x", "fix/x", "hotfix/x"):
            bridge._assert_feature_branch(branch)

    def test_sensitive_paths_are_rejected(self):
        bad = [".env", ".tools/private", "secrets/token.txt", "certs/key.pem", "app/config.php"]
        with self.assertRaises(RuntimeError):
            bridge._assert_no_sensitive_paths(bad)

    def test_normal_source_paths_are_allowed(self):
        bridge._assert_no_sensitive_paths(["drupal/web/modules/custom/example.css", ".ai/work/task.yaml"])

    def test_redaction_masks_common_secrets(self):
        text = bridge._redact("api_key=abcdef password=hunter2 sk-1234567890abcdef")
        self.assertNotIn("hunter2", text)
        self.assertNotIn("1234567890abcdef", text)


    def test_windows_kimi_shim_uses_node_entrypoint_without_cmd_shell(self):
        with TemporaryDirectory() as tmp:
            npm_root = Path(tmp)
            shim = npm_root / "kimi.cmd"
            shim.write_text("@echo off", encoding="utf-8")
            entry = npm_root / "node_modules" / "@moonshot-ai" / "kimi-code" / "dist" / "main.mjs"
            entry.parent.mkdir(parents=True)
            entry.write_text("", encoding="utf-8")
            node = npm_root / "node.exe"
            node.write_text("", encoding="utf-8")
            with patch.object(bridge.os, "name", "nt"), \
                 patch.object(bridge, "_resolve_kimi_cli", return_value=str(shim)), \
                 patch.object(bridge.shutil, "which", side_effect=lambda name: str(node) if name in ("node.exe", "node") else None):
                command = bridge._kimi_command("-p", "task with spaces & shell chars")
            self.assertEqual(command[0], str(node.resolve()))
            self.assertEqual(command[1], str(entry.resolve()))
            self.assertEqual(command[-2:], ["-p", "task with spaces & shell chars"])



if __name__ == "__main__":
    unittest.main()
