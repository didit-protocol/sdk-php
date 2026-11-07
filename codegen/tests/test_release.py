"""How a spec sync releases (docs/DESIGN.md sections 3.1 and 13): the type and
the breaking changes for two real upstream changes, the release-please
configuration, and the override block of a sync pushed on top of people's commits.
"""

from __future__ import annotations

import hashlib
import json
import re
import shutil
import tempfile
import unittest
from pathlib import Path

from helpers import REPO
import sync_pull_request
from didit_codegen.sync import (
    API_CHANGE_TYPE, API_CHANGES_SECTION, CUT, MAX_DESCRIPTION, RELEASES, TOO_LONG, Breaking, Report, Result, continue_pull_request,
    render, sync,
)

FIXTURES = REPO / "codegen" / "tests" / "fixtures" / "spec-sync"
# openapi/verification.json at these didit-protocol/sdk-python commits, with the start of its SHA-256.
PINNED = {
    "2026-11-04/before.json": ("9fe57fc69299601e59cc7a64c1db370fea5c7461", "12b2fdb05572755d"),
    "2026-11-04/after.json": ("e002a815773bbd1599256e2774046ee76520c6c8", "d1783bd862848198"),
    "2026-11-06/before.json": ("fe9f57a1dfb6374bfd391750d2044fc7a206e4ea", "9f8cdf8be8157588"),
    "2026-11-06/after.json": ("ccc799410f82eee1c25c7ce5abc6caa53846be6b", "1b0c7bdd8d7957bd"),
}
DECISION = "GET /v3/session/{sessionId}/decision/"
IMPORT_SHARED = "POST /v3/session/import-shared/"
VALIDATE = "POST /v3/session/{sessionId}/signatures/{nodeId}/validate/"
# The API change of 2026-11-04, as every server SDK reports it, in the form of openapi/breaking-vectors.json.
BREAKING_2026_11_04 = [
    f"{DECISION} response registry_checks[].company.registry_data: can now be null",
    f"{DECISION} response registry_checks[].ownership_structure: can now be null",
    f"{DECISION} response signatures[].confirmation.channel: can now be null",
    "POST /v3/email/risk/ response email.email_intelligence.reason_codes[]: type string -> object",
    "POST /v3/email/risk/ response email.enrichment.reason_codes[]: type string -> object",
    "POST /v3/kyb/select/ response kyb_registry.registry_data: can now be null",
    "POST /v3/kyb/shareholders/ response kyb_registry.registry_data: can now be null",
    "POST /v3/kyb/ubo/ response kyb_registry.registry_data: can now be null",
    "POST /v3/phone/risk/ response phone.enrichment.reason_codes[]: type string -> object",
    "POST /v3/phone/risk/ response phone.phone_intelligence.reason_codes[]: type string -> object",
    f"{IMPORT_SHARED} response registry_checks[].company.registry_data: can now be null",
    f"{IMPORT_SHARED} response registry_checks[].ownership_structure: can now be null",
    f"{IMPORT_SHARED} response signatures[].confirmation.channel: can now be null",
    f"{VALIDATE} response signatures[].confirmation.channel: can now be null",
]


def sync_pair(pair: str) -> Result:
    """The sync from ``<pair>/before.json`` to ``<pair>/after.json`` (the other documents unchanged)."""
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        shutil.copytree(REPO / "openapi", root / "openapi")
        (root / "src").mkdir()
        shutil.copy(REPO / "src" / "Version.php", root / "src" / "Version.php")
        shutil.copy(FIXTURES / pair / "before.json", root / "openapi" / "verification.json")
        fetched = {
            "verification.json": (FIXTURES / pair / "after.json").read_bytes(),
            "account.json": (REPO / "openapi" / "account.json").read_bytes(),
            "authentication.json": (REPO / "openapi" / "authentication.json").read_bytes(),
        }
        return sync(root, fetched, pair, "2026-11-06T00:00:00Z")


# release-please 17.6.0 (pinned by googleapis/release-please-action v5.0.0) -------------------


def config() -> dict[str, object]:
    package = json.loads((REPO / "release-please-config.json").read_text("utf-8"))["packages"]["."]
    assert isinstance(package, dict)
    return package


def release_commits(message: str, pull_request_body: str | None) -> list[tuple[str, bool, str]]:
    """``(type, breaking, description)`` of each commit release-please reads from one
    commit: the pull request's BEGIN_COMMIT_OVERRIDE block replaces the message,
    and BEGIN_NESTED_COMMIT blocks or blank lines before a standard type split it
    (preprocessCommitMessage and splitMessages in src/commit.ts)."""
    if pull_request_body is not None:
        override = pull_request_body.split("BEGIN_COMMIT_OVERRIDE", 1)[1:]
        text = override[0].split("END_COMMIT_OVERRIDE", 1)[0].strip() if override else ""
        message = text or message
    parts = message.split("BEGIN_NESTED_COMMIT")
    messages = [parts[0]]
    for part in parts[1:]:
        nested, *rest = part.split("END_NESTED_COMMIT")
        messages.append(nested)
        messages[0] += "END_NESTED_COMMIT".join(rest)
    standard = r"(?:feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)"
    split = [m for m in re.split(rf"\r?\n\r?\n(?={standard}(?:\(.*?\))?: )", messages[0]) if m] + messages[1:]
    out = []
    for raw in split:
        header = raw.strip().split("\n", 1)[0]
        match = re.match(r"^(\w+)(?:\(([^)]*)\))?(!)?: (.+)$", header)
        if match is None:
            continue  # "could not be parsed", skipped
        breaking = bool(match.group(3)) or bool(re.search(r"^BREAKING[ -]CHANGE: ", raw, re.M))
        out.append((match.group(1), breaking, match.group(4)))
    return out


def release(commits: list[tuple[str, bool, str]]) -> tuple[str | None, dict[str, list[str]]]:
    """The version bump and the release notes sections: hidden types leave the notes
    (conventional-changelog-conventionalcommits), empty notes open no release pull
    request (BaseStrategy.buildReleasePullRequest), breaking is major and feat is
    minor from 1.0.0 on (DefaultVersioningStrategy)."""
    sections = {s["type"]: s for s in config()["changelog-sections"]}  # type: ignore[index, union-attr]
    notes: dict[str, list[str]] = {}
    for kind, breaking, description in commits:
        entry = sections.get(kind)
        if breaking:
            notes.setdefault("BREAKING CHANGES", []).append(description)
        if entry is not None and not entry.get("hidden"):
            notes.setdefault(entry["section"], []).append(description)
    if not notes:
        return None, {}
    if any(breaking for _, breaking, _ in commits):
        return "major", notes
    return ("minor" if any(kind == "feat" for kind, _, _ in commits) else "patch"), notes


def squash(summary: str) -> list[tuple[str, bool, str]]:
    """The commits release-please reads after a squash merge of a spec sync pull request."""
    title, _, body = summary.partition("\n")
    return release_commits(title, body)


class FixtureTest(unittest.TestCase):
    def test_fixtures_are_the_pinned_upstream_copies(self) -> None:
        for name, (_commit, prefix) in PINNED.items():
            with self.subTest(name=name):
                self.assertTrue(hashlib.sha256((FIXTURES / name).read_bytes()).hexdigest().startswith(prefix))

    def test_the_api_change_of_2026_11_04_is_a_minor_release_with_its_breaking_changes(self) -> None:
        result = sync_pair("2026-11-04")
        report = result.report
        self.assertEqual(report.title, "feat(spec): sync API spec 2026-11-04")
        self.assertEqual([b.line for b in report.sorted_breaking], BREAKING_2026_11_04)

        summary = result.summary
        breaking = summary.split("## Breaking changes\n", 1)[1].split("\n## ", 1)[0]
        listed = [line for line in breaking.splitlines() if line.startswith("- ")]
        self.assertEqual(len(listed), len(BREAKING_2026_11_04))
        self.assertIn(
            "- `email.assess_risk()` (`POST /v3/email/risk/`): response `email.enrichment.reason_codes[]`: "
            "type changed from string to object",
            listed,
        )
        self.assertIn(
            "- `kyb.list_shareholders()` (`POST /v3/kyb/shareholders/`): response `kyb_registry.registry_data`: can now be null",
            listed,
        )
        bump, notes = release(squash(summary))
        self.assertEqual(bump, "minor")
        self.assertEqual(notes["Features"], ["sync API spec 2026-11-04"])
        self.assertEqual(notes[API_CHANGES_SECTION], [line[2:] for line in listed])
        self.assertNotIn("BREAKING CHANGES", notes)
        self.assertNotIn("BREAKING CHANGE", summary + report.commit_message)

    def test_a_documentation_only_sync_releases_nothing(self) -> None:
        result = sync_pair("2026-11-06")
        self.assertEqual(result.report.title, "chore(spec): sync API spec 2026-11-06")
        self.assertEqual(result.report.breaking, [])
        self.assertIn("## Breaking changes\n\nNone detected.\n", result.summary)
        self.assertIn("- `POST /v3/poa/` (`proof_of_address.verify`): request body, response 200 (documentation only)", result.summary)
        self.assertEqual(squash(result.summary), [("chore", False, "sync API spec 2026-11-06")])
        self.assertEqual(release(squash(result.summary)), (None, {}))


class ReleasePleaseConfigTest(unittest.TestCase):
    def test_sections(self) -> None:
        sections = {s["type"]: s for s in config()["changelog-sections"]}  # type: ignore[index, union-attr]
        self.assertEqual(sections[API_CHANGE_TYPE], {"type": API_CHANGE_TYPE, "section": API_CHANGES_SECTION})
        self.assertEqual(sections["feat"], {"type": "feat", "section": "Features"})
        self.assertFalse(sections["fix"].get("hidden"))
        for kind in ("chore", "docs", "style", "refactor", "test", "build", "ci"):
            self.assertTrue(sections[kind]["hidden"], kind)
        # "API changes that can break typed code" opens the release notes.
        self.assertEqual(config()["changelog-sections"][0]["type"], API_CHANGE_TYPE)  # type: ignore[index]

    def test_what_releases(self) -> None:
        self.assertEqual(release(release_commits("chore(spec): sync API spec 2026-11-06", None)), (None, {}))
        self.assertEqual(release(release_commits("chore(deps-dev): update guzzlehttp/guzzle", None)), (None, {}))
        self.assertEqual(release(release_commits("ci: cache composer", None)), (None, {}))
        self.assertEqual(release(release_commits("fix: retry on 503", None)), ("patch", {"Bug Fixes": ["retry on 503"]}))
        # A merge commit of a pull request without an override block reads its title.
        merge = "Merge pull request #30 from didit-protocol/chore/spec-sync\n\nfeat(spec): sync API spec 2026-11-07"
        self.assertEqual(release(release_commits(merge, None))[0], "minor")
        # The override wins over the commit, so a squash with the title keeps the type.
        body = render(Report("2026-11-07", observable=True))
        self.assertEqual(release(release_commits("chore(spec): sync API spec 2026-11-07", body))[0], "minor")


def _breaking(body: str) -> str:
    return body.split("## Breaking changes\n", 1)[1].split("\n## ", 1)[0]


def _report(date: str, observable: bool = False, breaking: tuple[str, ...] = ()) -> Report:
    report = Report(date, observable=observable)
    report.breaking = [
        Breaking(f"POST /v3/{field}/", f"POST /v3/{field}/ response {field}: can now be null", f"`x.{field}()`: `{field}` can now be null")
        for field in breaking
    ]
    return report


def _summary(date: str, observable: bool = False, breaking: tuple[str, ...] = ()) -> str:
    return render(_report(date, observable, breaking))


def _many(count: int) -> tuple[str, ...]:
    """Field names long enough that ``count`` release lines take about 150 characters each."""
    return tuple(f"field_{n:04}_" + "x" * 40 for n in range(count))


class NoCapTest(unittest.TestCase):
    """Every counted change reaches the release notes, with no cap (DESIGN 3.1)."""

    def test_every_line_is_in_the_block(self) -> None:
        # Well past the 30000 characters the block used to stop at.
        summary = _summary("2026-11-07", breaking=_many(250))
        self.assertGreater(len(summary.split("BEGIN_COMMIT_OVERRIDE", 1)[1]), 30000)
        self.assertNotIn("more, listed", summary)
        bump, notes = release(squash(summary))
        self.assertEqual((bump, len(notes[API_CHANGES_SECTION])), ("minor", 250))
        self.assertLess(len(summary), 65536)

    def test_lines_that_do_not_fit_a_description_are_in_the_commit(self) -> None:
        report = _report("2026-11-07", breaking=_many(600))
        summary = render(report)
        self.assertLess(len(summary), 65536)
        self.assertNotIn("BEGIN_COMMIT_OVERRIDE", summary)
        self.assertIn(TOO_LONG, summary)
        self.assertIn(RELEASES["feat"], summary)
        # Without a block, release-please reads the squashed commit: the sync commit's message.
        bump, notes = release(release_commits(report.commit_message, summary.partition("\n")[2]))
        self.assertEqual((bump, len(notes[API_CHANGES_SECTION])), ("minor", 600))

    def test_a_later_sync_that_does_not_fit_drops_the_block(self) -> None:
        previous = _summary("2026-11-05", breaking=("a",)).partition("\n")[2]
        title, body, comment = continue_pull_request("feat(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06", breaking=_many(600)))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        self.assertNotIn("BEGIN_COMMIT_OVERRIDE", body)
        self.assertNotIn("Release Please reads the block below", body)
        self.assertEqual(body.count(TOO_LONG), 1)
        self.assertIn(TOO_LONG, comment)
        # A later sync that fits keeps it that way: the commits still carry every line.
        _, body2, _ = continue_pull_request(title, body, _summary("2026-11-07", breaking=("b",)))
        self.assertNotIn("BEGIN_COMMIT_OVERRIDE", body2)
        self.assertEqual(body2.count(TOO_LONG), 1)
        self.assertIn("\n- `x.b()`: `b` can now be null\n", _breaking(body2))

    def test_a_later_sync_that_makes_the_description_too_long_drops_the_block(self) -> None:
        # Each block fits on its own; the description with both syncs' lines does not.
        fields = _many(210)
        previous = _summary("2026-11-05", breaking=fields[:180]).partition("\n")[2]
        self.assertIn("BEGIN_COMMIT_OVERRIDE", previous)
        title, body, comment = continue_pull_request("feat(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06", breaking=fields[180:]))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        self.assertLessEqual(len(body), MAX_DESCRIPTION)
        self.assertNotIn("BEGIN_COMMIT_OVERRIDE", body)
        self.assertEqual(body.count(TOO_LONG), 1)
        self.assertIn(TOO_LONG, comment)
        self.assertEqual(len([line for line in _breaking(body).splitlines() if line.startswith("- ")]), 210)
        self.assertNotIn(CUT, body)

    def test_a_description_that_still_does_not_fit_cuts_the_list(self) -> None:
        previous = _summary("2026-11-05", breaking=_many(600)).partition("\n")[2]
        others = tuple(f"other_{n:04}_" + "y" * 40 for n in range(600))
        title, body, _ = continue_pull_request("feat(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06", breaking=others))
        self.assertLessEqual(len(body), MAX_DESCRIPTION)
        self.assertNotIn("BEGIN_COMMIT_OVERRIDE", body)
        self.assertEqual((body.count(TOO_LONG), _breaking(body).count(CUT)), (1, 1))
        self.assertIn("## Later syncs\n\n- 2026-11-06 (`feat(spec)`)", body)
        # A later sync keeps saying so, once, and stays within the limit.
        _, body2, _ = continue_pull_request(title, body, _summary("2026-11-07", breaking=("b",)))
        self.assertLessEqual(len(body2), MAX_DESCRIPTION)
        self.assertEqual((body2.count(TOO_LONG), _breaking(body2).count(CUT)), (1, 1))
        self.assertIn("- 2026-11-07 (`feat(spec)`)", body2)


class ContinuePullRequestTest(unittest.TestCase):
    """A sync on top of commits from people adds to the override block instead of replacing it."""

    def test_feat_wins_and_the_lines_add_up(self) -> None:
        previous = "Hand-written intro.\n\n" + _summary("2026-11-05").partition("\n")[2]
        title, body, comment = continue_pull_request("chore(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06", breaking=("a",)))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        self.assertTrue(body.startswith("Hand-written intro.\n"))
        self.assertTrue(body.endswith(
            "BEGIN_COMMIT_OVERRIDE\nfeat(spec): sync API spec 2026-11-06\n\n"
            "BEGIN_NESTED_COMMIT\napi(spec): `x.a()`: `a` can now be null\nEND_NESTED_COMMIT\nEND_COMMIT_OVERRIDE\n"
        ), body)
        self.assertNotIn("END_COMMIT_OVERRIDE", comment)
        self.assertIn("- `x.a()`: `a` can now be null", comment)
        self.assertEqual(release(release_commits(title, body))[0], "minor")

        # A later documentation-only sync keeps feat and the earlier lines, once each.
        title2, body2, _ = continue_pull_request(title, body, _summary("2026-11-07", breaking=("a", "b")))
        title3, body3, _ = continue_pull_request(title2, body2, _summary("2026-11-08"))
        self.assertEqual(title3, "feat(spec): sync API spec 2026-11-08")
        self.assertEqual(body3.count("api(spec): `x.a()`"), 1)
        self.assertEqual(body3.count("api(spec): `x.b()`"), 1)
        self.assertEqual(
            [line for line in _breaking(body3).splitlines() if line.startswith("- ")],
            ["- `x.a()`: `a` can now be null", "- `x.b()`: `b` can now be null"],
        )
        self.assertIn(RELEASES["feat"], body3)
        later = body3.split("## Later syncs\n\n", 1)[1].split("\n\n", 1)[0].splitlines()
        self.assertEqual([line[:23] for line in later], ["- 2026-11-06 (`feat(spe", "- 2026-11-07 (`feat(spe", "- 2026-11-08 (`chore(sp"])
        self.assertLess(body3.index("## Later syncs"), body3.index("## Release"))
        bump, notes = release(release_commits(title3, body3))
        self.assertEqual((bump, len(notes[API_CHANGES_SECTION])), ("minor", 2))

    def test_a_breaking_sync_on_a_documentation_only_pull_request_updates_the_description(self) -> None:
        previous = _summary("2026-11-05").partition("\n")[2].lstrip("\n")
        self.assertIn("None detected.", previous)
        self.assertIn(RELEASES["chore"], previous)
        title, body, _ = continue_pull_request("chore(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06", breaking=("a",)))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        self.assertTrue(body.startswith("## Breaking changes\n\nThese changes mirror the API"), body)
        self.assertIn("\n- `x.a()`: `a` can now be null\n", _breaking(body))
        self.assertNotIn("None detected", body)
        self.assertNotIn(RELEASES["chore"], body)
        self.assertNotIn("releases nothing", body)
        self.assertEqual(body.count(RELEASES["feat"]), 1)
        self.assertEqual(release(release_commits(title, body))[0], "minor")

    def test_documentation_only_stays_chore(self) -> None:
        previous = _summary("2026-11-05").partition("\n")[2].replace("\n", "\r\n")
        title, body, _ = continue_pull_request("chore(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06"))
        self.assertEqual(title, "chore(spec): sync API spec 2026-11-06")
        self.assertIn("BEGIN_COMMIT_OVERRIDE\nchore(spec): sync API spec 2026-11-06\nEND_COMMIT_OVERRIDE", body)
        self.assertIn("## Breaking changes\n\nNone detected.\n", body)
        self.assertIn(RELEASES["chore"], body)
        self.assertEqual(release(release_commits(title, body)), (None, {}))

    def test_without_a_block_the_earlier_syncs_count_as_feat(self) -> None:
        title, body, comment = continue_pull_request("chore(spec): sync API spec 2026-11-05", "Old description.", _summary("2026-11-06"))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        # Nothing recorded the earlier breaking changes, so the list does not claim to be complete.
        self.assertTrue(body.startswith(
            "## Breaking changes\n\nNone detected.\n\nThe description had no such list before the sync of 2026-11-06"
        ), body)
        self.assertIn("\nOld description.\n\n## Later syncs\n", body)
        self.assertIn("## Release\n\n" + RELEASES["feat"] + "\n", body)
        self.assertIn("count as `feat(spec)`", comment)
        # A later sync adds its entries and keeps saying so.
        _, body2, _ = continue_pull_request(title, body, _summary("2026-11-07", breaking=("a",)))
        self.assertIn("\n- `x.a()`: `a` can now be null\n", _breaking(body2))
        self.assertIn("before the sync of 2026-11-06: changes of earlier syncs", _breaking(body2))
        self.assertEqual(body2.count("## Breaking changes"), 1)

    def test_a_first_line_someone_wrote_stays(self) -> None:
        previous = "BEGIN_COMMIT_OVERRIDE\nfeat(webhooks): verify the new event\nEND_COMMIT_OVERRIDE\n"
        title, body, _ = continue_pull_request("feat(spec): sync API spec 2026-11-05", previous, _summary("2026-11-06"))
        self.assertEqual(title, "feat(spec): sync API spec 2026-11-06")
        commits = release_commits(title, body)
        self.assertEqual(commits, [("feat", False, "verify the new event"), ("feat", False, "sync API spec 2026-11-06")])

    def test_the_command_the_workflow_runs(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp)
            (out / "previous.json").write_text(json.dumps({
                "title": "chore(spec): sync API spec 2026-11-05", "body": _summary("2026-11-05").partition("\n")[2],
            }), "utf-8")
            (out / "summary.md").write_text(_summary("2026-11-06", observable=True), "utf-8")
            code = sync_pull_request.main([
                "--previous", str(out / "previous.json"), "--summary", str(out / "summary.md"), "--out", str(out / "continued"),
            ])
            self.assertEqual(code, 0)
            self.assertEqual((out / "continued" / "title.txt").read_text("utf-8"), "feat(spec): sync API spec 2026-11-06\n")
            self.assertIn("BEGIN_COMMIT_OVERRIDE\nfeat(spec): sync API spec 2026-11-06\nEND_COMMIT_OVERRIDE", (out / "continued" / "body.md").read_text("utf-8"))
            self.assertTrue((out / "continued" / "comment.md").read_text("utf-8").startswith("## Breaking changes\n"))


if __name__ == "__main__":
    unittest.main()
