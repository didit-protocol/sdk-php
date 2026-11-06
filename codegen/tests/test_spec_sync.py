"""codegen/spec_sync.py end to end with --offline-dir on a temp copy of openapi/ (docs/DESIGN.md section 3.1)."""

from __future__ import annotations

import contextlib
import hashlib
import io
import json
import re
import shutil
import tempfile
import unittest
from pathlib import Path
from typing import Any, Callable

from helpers import REPO
import spec_sync
from didit_codegen.generate import build

DATE = "2026-10-17"
REMOVED = "GET /v3/session/imports/{importId}/"
TEMPLATE = "GET /v3/session/imports/template/"
ERRORS = "GET /v3/session/imports/{importId}/errors/"
# The SDK version the deprecations are recorded against, and the next minor release, which ends them.
CURRENT = re.search(r"VERSION = '([^']+)'", (REPO / "src" / "Version.php").read_text("utf-8")).group(1)
_major, _minor, _patch = (int(part) for part in CURRENT.split("."))
NEXT_MINOR = f"{_major}.{_minor + 1}.0"


def _sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class SpecSyncTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        # The baseline names these three operations through the overlay, whatever the live documents do:
        # once upstream carries x-didit-sdk the sync deletes their overlay entries (docs/DESIGN.md section 3),
        # and the tests below need them to start in the overlay.
        spec = json.loads((REPO / "openapi" / "verification.json").read_text("utf-8"))
        overlay_doc = json.loads((REPO / "openapi" / "overlay.json").read_text("utf-8"))
        cls.overlay = {}
        for key in (REMOVED, TEMPLATE, ERRORS):
            verb, path = key.split(" ", 1)
            op = spec["paths"][path][verb.lower()]
            cls.overlay[key] = op.pop("x-didit-sdk", None) or overlay_doc["operations"][key]
            overlay_doc["operations"][key] = cls.overlay[key]
        cls.verification = spec
        cls.verification_text = json.dumps(spec, indent=2) + "\n"
        cls.overlay_text = json.dumps(overlay_doc, indent=1)

    def setUp(self) -> None:
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        shutil.copytree(REPO / "openapi", self.root / "openapi")
        (self.root / "src").mkdir()
        shutil.copy(REPO / "src" / "Version.php", self.root / "src" / "Version.php")
        self.offline = self.root / "upstream"
        self.offline.mkdir()
        (self.root / "openapi" / "verification.json").write_text(self.verification_text, "utf-8")
        (self.root / "openapi" / "overlay.json").write_text(self.overlay_text, "utf-8")
        (self.offline / "openapi-25.json").write_text(self.verification_text, "utf-8")
        shutil.copy(REPO / "openapi" / "account.json", self.offline / "openapi-auth.json")
        shutil.copy(REPO / "openapi" / "authentication.json", self.offline / "openapi-authentication.json")
        self.summary = self.root / "summary.md"
        self.commit = self.root / "commit.txt"

    # helpers ----------------------------------------------------------------

    def upstream(self, mutate: Callable[[dict[str, Any]], None]) -> None:
        doc = json.loads(json.dumps(self.verification))
        mutate(doc)
        (self.offline / "openapi-25.json").write_text(json.dumps(doc, indent=2) + "\n", "utf-8")

    def upstream_authentication(self, mutate: Callable[[dict[str, Any]], None]) -> None:
        doc = json.loads((REPO / "openapi" / "authentication.json").read_text("utf-8"))
        mutate(doc)
        (self.offline / "openapi-authentication.json").write_text(json.dumps(doc, indent=2) + "\n", "utf-8")

    def sync(self, *extra: str) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        self.commit.unlink(missing_ok=True)
        args = [
            "--root", str(self.root), "--offline-dir", str(self.offline), "--summary-file", str(self.summary),
            "--commit-message-file", str(self.commit), "--date", DATE, *extra,
        ]
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = spec_sync.main(args)
        return code, out.getvalue(), err.getvalue()

    def snapshot(self) -> dict[str, str]:
        return {p.name: _sha(p) for p in sorted((self.root / "openapi").iterdir())}

    def read(self, name: str) -> Any:
        return json.loads((self.root / "openapi" / name).read_text("utf-8"))

    @staticmethod
    def remove(doc: dict[str, Any]) -> None:
        del doc["paths"]["/v3/session/imports/{importId}/"]["get"]

    # tests ------------------------------------------------------------------

    def test_no_change_writes_nothing(self) -> None:
        before = self.snapshot()
        code, out, _ = self.sync()
        self.assertEqual(code, 0)
        self.assertEqual(out.strip(), "No upstream change")
        self.assertEqual(self.snapshot(), before)
        self.assertFalse(self.summary.exists())

    def test_added_operation_without_naming_fails_and_names_it(self) -> None:
        def add(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/new-thing/"] = {"post": {"summary": "New", "responses": {"200": {"description": "ok"}}}}
        self.upstream(add)
        before = self.snapshot()
        code, _, err = self.sync()
        self.assertEqual(code, 2)
        self.assertIn("have no SDK naming (x-didit-sdk)", err)
        self.assertIn("  - POST /v3/new-thing/", err)
        self.assertEqual(self.snapshot(), before)

    def test_refuses_a_download_that_is_not_openapi(self) -> None:
        (self.offline / "openapi-auth.json").write_text('{"error": "not found"}', "utf-8")
        before = self.snapshot()
        code, _, err = self.sync()
        self.assertEqual(code, 1)
        self.assertIn("is not an OpenAPI document", err)
        (self.offline / "openapi-auth.json").write_text("<html>", "utf-8")
        code, _, err = self.sync()
        self.assertEqual(code, 1)
        self.assertIn("is not valid JSON", err)
        self.assertEqual(self.snapshot(), before)

    def test_removed_operation_is_deprecated(self) -> None:
        self.upstream(self.remove)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)

        [entry] = self.read("deprecated-operations.json")["operations"]
        self.assertEqual(entry["key"], REMOVED)
        self.assertEqual(entry["x-didit-sdk"], {"resource": "sessions.imports", "method": "retrieve", "auth": "api_key", "server": True})
        self.assertEqual(entry["removed_on"], DATE)
        self.assertEqual(entry["deprecated_since"], CURRENT)
        self.assertIn("responses", entry["operation"])
        self.assertNotIn("#/components/responses", json.dumps(entry))
        self.assertIn("SessionImportJob", entry["components"]["schemas"])
        self.assertNotIn(REMOVED, self.read("overlay.json")["operations"])

        summary = self.summary.read_text("utf-8")
        self.assertEqual(summary.splitlines()[0], f"feat(spec): sync API spec {DATE}")
        self.assertTrue(summary.splitlines()[2] == "## Breaking changes", summary[:200])
        breaking = summary.split("## Breaking changes", 1)[1].split("\n## ", 1)[0]
        line = f"`{REMOVED}` was removed upstream: `sessions.imports.retrieve()` is deprecated and will be deleted in the next minor version"
        self.assertIn(f"- {line}", breaking)
        self.assertIn("**Removed (1)**", summary)
        # A minor release (DESIGN 13): feat(spec) plus one api(spec) line per breaking change,
        # in the commit and in the override block release-please reads, never a BREAKING CHANGE footer.
        commit = self.commit.read_text("utf-8")
        self.assertEqual(commit.splitlines()[0], f"feat(spec): sync API spec {DATE}")
        self.assertIn(f"BEGIN_NESTED_COMMIT\napi(spec): {line}\nEND_NESTED_COMMIT", commit)
        self.assertTrue(summary.endswith(
            f"BEGIN_COMMIT_OVERRIDE\nfeat(spec): sync API spec {DATE}\n\n"
            f"BEGIN_NESTED_COMMIT\napi(spec): {line}\nEND_NESTED_COMMIT\nEND_COMMIT_OVERRIDE\n"
        ), summary[-600:])
        for text in (summary, commit):
            self.assertNotIn("BREAKING CHANGE", text)
            self.assertNotIn("(spec)!", text)

        # The generator still builds the method, with its component type, from the entry alone.
        files, _ = build(self.root)
        imports = files["src/Resources/Sessions/Imports.php"]
        self.assertIn("public function retrieve(string $importId, ?RequestOptions $options = null): Types\\SessionImportJob", imports)
        self.assertIn(f"@deprecated this operation was removed from the API on {DATE}", imports)

        # A second run with the same upstream has nothing to do.
        code, out, _ = self.sync()
        self.assertEqual((code, out.strip()), (0, "No upstream change"))

    def test_operation_that_stops_being_a_server_operation_is_deprecated(self) -> None:
        def withdraw(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/session/imports/{importId}/errors/"]["get"]["x-didit-sdk"] = dict(self.overlay[ERRORS], server=False)
        self.upstream(withdraw)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)

        [entry] = self.read("deprecated-operations.json")["operations"]
        self.assertEqual(entry["key"], ERRORS)
        self.assertEqual(entry["x-didit-sdk"], self.overlay[ERRORS])
        self.assertEqual((entry["removed_on"], entry["deprecated_since"]), (DATE, CURRENT))
        self.assertIn("responses", entry["operation"])
        self.assertNotIn(ERRORS, self.read("overlay.json")["operations"])
        summary = self.summary.read_text("utf-8")
        self.assertIn(f"`{ERRORS}` is no longer a server operation: `sessions.imports.list_errors()` is deprecated", summary)
        self.assertIn(f"api(spec): `{ERRORS}` is no longer a server operation", self.commit.read_text("utf-8"))
        self.assertNotIn("BREAKING CHANGE", self.commit.read_text("utf-8"))

        # The method stays, deprecated, until the next minor version.
        files, _ = build(self.root)
        imports = files["src/Resources/Sessions/Imports.php"]
        self.assertIn("public function listErrors(", imports)
        self.assertIn(f"@deprecated this operation stopped being a server operation on {DATE}", imports)
        code, out, _ = self.sync()
        self.assertEqual((code, out.strip()), (0, "No upstream change"))

        version = self.root / "src" / "Version.php"
        version.write_text(version.read_text("utf-8").replace(f"'{CURRENT}'", f"'{NEXT_MINOR}'"), "utf-8")
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        self.assertIn(f"`sessions.imports.list_errors()` deleted (deprecated since {CURRENT})", self.commit.read_text("utf-8"))
        self.assertNotIn("public function listErrors(", build(self.root)[0]["src/Resources/Sessions/Imports.php"])

    def test_operation_that_becomes_a_server_operation_again_is_not_deprecated(self) -> None:
        def withdraw(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/session/imports/{importId}/errors/"]["get"]["x-didit-sdk"] = dict(self.overlay[ERRORS], server=False)
        self.upstream(withdraw)
        self.assertEqual(self.sync()[0], 0)
        def restore(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/session/imports/{importId}/errors/"]["get"]["x-didit-sdk"] = dict(self.overlay[ERRORS])
        self.upstream(restore)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        self.assertIn(f"`{ERRORS}` came back upstream: `sessions.imports.list_errors` is no longer deprecated", self.summary.read_text("utf-8"))
        self.assertIn("public function listErrors(", build(self.root)[0]["src/Resources/Sessions/Imports.php"])

    def test_breaking_change_to_an_operation_is_summarised_and_flagged(self) -> None:
        def retype(doc: dict[str, Any]) -> None:
            schema = doc["paths"]["/v3/session/imports/{importId}/errors/"]["get"]["responses"]["200"]["content"]["application/json"]["schema"]
            schema["properties"]["count"]["type"] = "string"
        self.upstream(retype)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        summary = self.summary.read_text("utf-8")
        changed = summary.split("**Changed (1)**", 1)[1]
        self.assertIn(f"- `{ERRORS}` (`sessions.imports.list_errors`): ", changed)
        self.assertIn("breaking: response 200 `count`: type changed from integer to string", changed)
        breaking = summary.split("## Breaking changes", 1)[1].split("\n## ", 1)[0]
        line = f"`sessions.imports.list_errors()` (`{ERRORS}`): response 200 `count`: type changed from integer to string"
        self.assertIn(line, breaking)
        self.assertIn(f"api(spec): {line}", summary.split("BEGIN_COMMIT_OVERRIDE", 1)[1])
        self.assertTrue(self.commit.read_text("utf-8").startswith(f"feat(spec): sync API spec {DATE}\n"))
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())

    def test_renamed_type_is_a_feature_and_listed(self) -> None:
        # Same content under a new component name: the generator renames the public type.
        def rename(doc: dict[str, Any]) -> None:
            schemas = doc["components"]["schemas"]
            schemas["SessionDecisionV2"] = schemas.pop("SessionDecision")
            text = json.dumps(doc).replace('"#/components/schemas/SessionDecision"', '"#/components/schemas/SessionDecisionV2"')
            doc.clear()
            doc.update(json.loads(text))
        self.upstream(rename)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        summary = self.summary.read_text("utf-8")
        self.assertTrue(summary.startswith(f"feat(spec): sync API spec {DATE}\n"))
        breaking = summary.split("## Breaking changes", 1)[1].split("\n## ", 1)[0]
        line = "`sessions.retrieve_decision()` (`GET /v3/session/{sessionId}/decision/`): response 200: type changed from SessionDecision to SessionDecisionV2"
        self.assertIn(f"- {line}", breaking)
        self.assertIn(f"api(spec): {line}", summary.split("BEGIN_COMMIT_OVERRIDE", 1)[1])
        self.assertIn("- removed `SessionDecision`", summary)

    def test_deprecation_ends_after_one_minor_version(self) -> None:
        self.upstream(self.remove)
        self.assertEqual(self.sync()[0], 0)
        version = self.root / "src" / "Version.php"
        version.write_text(version.read_text("utf-8").replace(f"'{CURRENT}'", f"'{NEXT_MINOR}'"), "utf-8")
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        self.assertIn(f"`sessions.imports.retrieve()` deleted (deprecated since {CURRENT})", self.summary.read_text("utf-8"))

    def test_operation_that_comes_back_gets_its_naming_back(self) -> None:
        self.upstream(self.remove)
        self.assertEqual(self.sync()[0], 0)
        (self.offline / "openapi-25.json").write_text(self.verification_text, "utf-8")
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        self.assertEqual(self.read("overlay.json")["operations"][REMOVED], self.overlay[REMOVED])

    def test_moved_operation_is_not_deprecated(self) -> None:
        def move(doc: dict[str, Any]) -> None:
            op = doc["paths"]["/v3/session/imports/{importId}/"].pop("get")
            op["x-didit-sdk"] = self.overlay[REMOVED]
            doc["paths"]["/v3/session-imports/{importId}/"] = {"get": op}
        self.upstream(move)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        self.assertIn("moved to `GET /v3/session-imports/{importId}/`", self.summary.read_text("utf-8"))

    def test_overlay_entry_dropped_when_upstream_carries_the_same_naming(self) -> None:
        def name(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/session/imports/template/"]["get"]["x-didit-sdk"] = dict(self.overlay[TEMPLATE])
        self.upstream(name)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        expected = json.loads(self.overlay_text)
        del expected["operations"][TEMPLATE]
        # The rest of the file, and its formatting, is kept.
        self.assertEqual((self.root / "openapi" / "overlay.json").read_text("utf-8"), json.dumps(expected, indent=1))
        self.assertIn(f"`{TEMPLATE}`: overlay entry deleted, upstream carries the same `x-didit-sdk`", self.summary.read_text("utf-8"))
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())

    def test_upstream_rename_keeps_a_deprecated_alias(self) -> None:
        def rename(doc: dict[str, Any]) -> None:
            doc["paths"]["/v3/session/imports/{importId}/errors/"]["get"]["x-didit-sdk"] = dict(self.overlay[ERRORS], method="errors_list")
        self.upstream(rename)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertNotIn(ERRORS, self.read("overlay.json")["operations"])
        [entry] = self.read("deprecated-operations.json")["operations"]
        self.assertEqual((entry["key"], entry["alias"], entry["x-didit-sdk"]["method"]), (ERRORS, True, "list_errors"))
        summary = self.summary.read_text("utf-8")
        self.assertIn("upstream `x-didit-sdk` differs and wins", summary)
        self.assertIn("`sessions.imports.list_errors()` renamed to `sessions.imports.errors_list()`", summary)

    def test_source_json_records_the_new_file(self) -> None:
        def change(doc: dict[str, Any]) -> None:
            doc["info"]["description"] = "Changed upstream."
        self.upstream(change)
        source_before = (self.root / "openapi" / "SOURCE.json").read_text("utf-8")
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertEqual(_sha(self.root / "openapi" / "verification.json"), _sha(self.offline / "openapi-25.json"))
        source = self.read("SOURCE.json")
        before = json.loads(source_before)
        entry = source["verification.json"]
        self.assertEqual(list(entry), ["url", "sha256", "fetched_at"])
        self.assertEqual(entry["url"], "https://docs.didit.me/openapi-25.json")
        self.assertEqual(entry["sha256"], _sha(self.offline / "openapi-25.json"))
        self.assertRegex(entry["fetched_at"], r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
        self.assertEqual(source["account.json"], before["account.json"])
        self.assertEqual(source["webhook-vectors.json"], before["webhook-vectors.json"])
        self.assertEqual((self.root / "openapi" / "SOURCE.json").read_text("utf-8"), json.dumps(source, indent=1))
        summary = self.summary.read_text("utf-8")
        self.assertIn("## Breaking changes\n\nNone detected.\n", summary)
        self.assertNotIn("BREAKING CHANGE", summary)
        # Documentation only: chore(spec), which releases nothing on its own.
        self.assertEqual(summary.splitlines()[0], f"chore(spec): sync API spec {DATE}")
        self.assertTrue(summary.endswith(f"BEGIN_COMMIT_OVERRIDE\nchore(spec): sync API spec {DATE}\nEND_COMMIT_OVERRIDE\n"))
        self.assertEqual(
            self.commit.read_text("utf-8"),
            f"chore(spec): sync API spec {DATE}\n\nGenerated by .github/workflows/spec-sync.yml from the upstream OpenAPI documents.\n",
        )
        self.assertTrue(re.search(r"\| `openapi/verification.json` \| https://docs.didit.me/openapi-25.json \| `[0-9a-f]{12}` -> `[0-9a-f]{12}` \|", summary))

    # authentication.json: synced and reported, never generated or deprecated (DESIGN 10) ------------

    def test_authentication_operation_added_upstream(self) -> None:
        def add(doc: dict[str, Any]) -> None:
            doc["paths"]["/sessions/"] = {"get": {
                "x-didit-sdk": {"resource": "authentication", "method": "list_sessions", "auth": "access_token", "server": True},
                "responses": {"200": {"description": "ok"}},
            }}
        self.upstream_authentication(add)
        before = self.snapshot()
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        after = self.snapshot()
        self.assertEqual(sorted(k for k in after if after[k] != before.get(k)), ["SOURCE.json", "authentication.json"])
        self.assertEqual(self.read("SOURCE.json")["authentication.json"]["url"], "https://docs.didit.me/openapi-authentication.json")
        summary = self.summary.read_text("utf-8")
        self.assertEqual(summary.splitlines()[0], f"feat(spec): sync API spec {DATE}")
        self.assertIn("- `authentication GET /sessions/`: `authentication.list_sessions()`, update `src/Authentication`", summary)
        self.assertIn("## Breaking changes\n\nNone detected.\n", summary)

    def test_authentication_operation_removed_upstream(self) -> None:
        def remove(doc: dict[str, Any]) -> None:
            del doc["paths"]["/step-up/{step_up_id}/"]
        self.upstream_authentication(remove)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        self.assertFalse((self.root / "openapi" / "deprecated-operations.json").exists())
        summary = self.summary.read_text("utf-8")
        line = "`authentication GET /step-up/{step_up_id}/` was removed upstream: `authentication.retrieve_step_up()` is removed with it"
        self.assertIn(f"- {line}", summary.split("## Breaking changes", 1)[1].split("\n## ", 1)[0])
        self.assertIn(f"api(spec): {line}", summary.split("BEGIN_COMMIT_OVERRIDE", 1)[1])

    def test_authentication_documentation_change(self) -> None:
        def describe(doc: dict[str, Any]) -> None:
            doc["paths"]["/session/"]["get"]["responses"]["200"]["description"] = "The session."
        self.upstream_authentication(describe)
        code, _, err = self.sync()
        self.assertEqual(code, 0, err)
        summary = self.summary.read_text("utf-8")
        self.assertEqual(summary.splitlines()[0], f"chore(spec): sync API spec {DATE}")
        self.assertIn("- `authentication GET /session/` (`authentication.get_session`): response 200 (documentation only)", summary)


if __name__ == "__main__":
    unittest.main()
