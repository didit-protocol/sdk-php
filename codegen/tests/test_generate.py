"""The generator entry point: --check, and deprecated operations (docs/DESIGN.md sections 3.1 and 5)."""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from helpers import CODEGEN, ok, write_sessions
from didit_codegen.generate import MARKER, build


def _run(root: Path, *args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(root / "codegen" / "generate.py"), *args],
        capture_output=True, text=True, check=False,
    )


class CheckModeTest(unittest.TestCase):
    def test_check_detects_changed_and_stale_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            shutil.copytree(CODEGEN, root / "codegen", ignore=shutil.ignore_patterns("__pycache__", "tests"))
            write_sessions(root)

            written = _run(root)
            self.assertEqual(written.returncode, 0, written.stderr)
            resource = root / "src" / "Resources" / "Sessions.php"
            self.assertIn(MARKER, resource.read_text("utf-8"))
            self.assertEqual(_run(root, "--check").returncode, 0)

            resource.write_text(resource.read_text("utf-8") + "// edited by hand\n", "utf-8")
            edited = _run(root, "--check")
            self.assertEqual(edited.returncode, 1)
            self.assertIn("src/Resources/Sessions.php", edited.stderr)
            self.assertIn("out of date", edited.stderr)

            self.assertEqual(_run(root).returncode, 0)
            stale = root / "src" / "Types" / "Removed.php"
            stale.write_text("<?php\n", "utf-8")
            check = _run(root, "--check")
            self.assertEqual(check.returncode, 1)
            self.assertIn("src/Types/Removed.php (stale, no longer generated)", check.stderr)

            self.assertEqual(_run(root).returncode, 0)
            self.assertFalse(stale.exists())


REMOVED_ENTRY = {
    "key": "GET /v3/session/{sessionId}/report/",
    "x-didit-sdk": {"resource": "sessions", "method": "retrieve_report", "auth": "api_key", "server": True},
    "removed_on": "2026-10-01",
    "deprecated_since": "0.1.0",
    "operation": {
        "summary": "Retrieve a report",
        "parameters": [{"name": "sessionId", "in": "path", "required": True, "schema": {"type": "string"}}],
        "responses": ok({"$ref": "#/components/schemas/SessionReport"}),
    },
    "components": {"schemas": {
        "SessionReport": {"type": "object", "properties": {"score": {"$ref": "#/components/schemas/Score"}}},
        "Score": {"type": "number"},
    }},
}

ALIAS_ENTRY = {
    "key": "GET /v3/session/{sessionId}/decision/",
    "alias": True,
    "x-didit-sdk": {"resource": "sessions", "method": "get_decision", "auth": "api_key", "server": True},
    "removed_on": "2026-10-01",
    "deprecated_since": "0.1.0",
}


class DeprecatedOperationsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            write_sessions(Path(tmp), deprecated=[REMOVED_ENTRY, ALIAS_ENTRY])
            cls.files, _ = build(Path(tmp))
        cls.sessions = cls.files["src/Resources/Sessions.php"]

    def test_removed_operation_is_a_deprecated_method(self) -> None:
        self.assertIn("public function retrieveReport(string $sessionId, ?RequestOptions $options = null): Types\\SessionReport", self.sessions)
        self.assertIn(
            "@deprecated this operation was removed from the API on 2026-10-01; this method will be deleted in the next minor version",
            self.sessions,
        )
        self.assertIn("path: '/v3/session/{sessionId}/report/',", self.sessions)

    def test_its_component_schemas_keep_their_names(self) -> None:
        self.assertIn("src/Types/SessionReport.php", self.files)
        self.assertIn("public readonly ?float $score;", self.files["src/Types/SessionReport.php"])

    def test_renamed_operation_keeps_a_deprecated_alias(self) -> None:
        self.assertIn("public function retrieveDecision(string $sessionId", self.sessions)
        self.assertIn("public function getDecision(string $sessionId", self.sessions)
        self.assertIn(
            "@deprecated renamed to `$didit->sessions->retrieveDecision()`; this alias will be deleted in the next minor version",
            self.sessions,
        )
        self.assertIn("public function testGetDecision(): void", self.files["tests/Generated/SessionsTest.php"])

    def test_an_operation_that_came_back_is_not_duplicated(self) -> None:
        came_back = dict(REMOVED_ENTRY, key="GET /v3/session/{sessionId}/decision/")
        came_back["x-didit-sdk"] = dict(REMOVED_ENTRY["x-didit-sdk"], method="retrieve_decision")
        with tempfile.TemporaryDirectory() as tmp:
            write_sessions(Path(tmp), deprecated=[came_back])
            files, _ = build(Path(tmp))
        self.assertEqual(files["src/Resources/Sessions.php"].count("public function retrieveDecision("), 1)
        self.assertNotIn("@deprecated", files["src/Resources/Sessions.php"])


if __name__ == "__main__":
    unittest.main()
