#!/usr/bin/env python3
"""Downloads the upstream API documents into openapi/ (docs/DESIGN.md section 3.1).

Usage:
    python3 codegen/spec_sync.py [--offline-dir DIR] [--summary-file PATH] [--commit-message-file PATH] [--date YYYY-MM-DD]

Updates openapi/verification.json, openapi/account.json and openapi/SOURCE.json
when upstream changed, maintains openapi/overlay.json and
openapi/deprecated-operations.json (including deleting deprecated methods
once they have shipped for one minor version), and writes the pull request
summary (Markdown, first line is the title) and the commit message, which
carries a BREAKING CHANGE footer when the sync breaks the SDK surface, so
release-please never ships it as a patch. Prints "No upstream change" and
writes nothing when there is nothing to do. Exit codes: 0 done, 1 download or
input error, 2 the new documents cannot be generated (for example an operation
without SDK naming); openapi/ is left untouched and the summary file, when
given, also says what to fix.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import shutil
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from didit_codegen.generate import build  # noqa: E402
from didit_codegen.spec import SpecError  # noqa: E402
from didit_codegen.sync import UPSTREAM, sync  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
TIMEOUT = 60


class SyncError(Exception):
    """A download or input problem."""


def fetch(url: str) -> bytes:
    request = urllib.request.Request(url, headers={"User-Agent": "didit-sdk-php-spec-sync", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            return response.read()
    except urllib.error.HTTPError as exc:
        raise SyncError(f"GET {url} failed: HTTP {exc.code} {exc.reason}") from exc
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise SyncError(f"GET {url} failed: {getattr(exc, 'reason', exc)}") from exc


def check_document(label: str, data: bytes) -> None:
    """Refuses anything that is not an OpenAPI document."""
    try:
        doc = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise SyncError(f"{label} is not valid JSON: {exc}") from exc
    if not isinstance(doc, dict) or "openapi" not in doc:
        raise SyncError(f"{label} is not an OpenAPI document (no `openapi` key)")
    if not isinstance(doc.get("paths"), dict):
        raise SyncError(f"{label} has no `paths` object")


def download(offline_dir: Path | None) -> dict[str, bytes]:
    out: dict[str, bytes] = {}
    for name, (url, offline_name, _, _) in UPSTREAM.items():
        if offline_dir is not None:
            path = offline_dir / offline_name
            try:
                data = path.read_bytes()
            except OSError as exc:
                raise SyncError(f"cannot read {path}: {exc.strerror}") from exc
            label = str(path)
        else:
            data = fetch(url)
            label = url
        check_document(label, data)
        out[name] = data
    return out


def validate(root: Path, files: dict[str, bytes | None]) -> None:
    """Builds the SDK from the updated openapi/ in a scratch directory; raises SpecError."""
    with tempfile.TemporaryDirectory() as tmp:
        shutil.copytree(root / "openapi", Path(tmp) / "openapi")
        for rel, content in files.items():
            path = Path(tmp) / rel
            if content is None:
                path.unlink(missing_ok=True)
            else:
                path.write_bytes(content)
        build(Path(tmp))


def write(root: Path, files: dict[str, bytes | None]) -> None:
    for rel, content in files.items():
        path = root / rel
        if content is None:
            path.unlink(missing_ok=True)
        else:
            path.write_bytes(content)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--offline-dir", type=Path, help="read openapi-25.json and openapi-auth.json from DIR instead of downloading")
    parser.add_argument("--summary-file", type=Path, help="write the pull request summary here (default: stdout)")
    parser.add_argument("--commit-message-file", type=Path, help="write the commit message here")
    parser.add_argument("--date", help="sync date YYYY-MM-DD (default: today, UTC)")
    parser.add_argument("--root", type=Path, default=ROOT, help=argparse.SUPPRESS)
    args = parser.parse_args(argv)

    now = dt.datetime.now(dt.timezone.utc)
    date = args.date or now.date().isoformat()
    try:
        dt.date.fromisoformat(date)
    except ValueError:
        parser.error(f"--date must be YYYY-MM-DD, got {date!r}")
    root: Path = args.root

    try:
        fetched = download(args.offline_dir)
        result = sync(root, fetched, date, now.strftime("%Y-%m-%dT%H:%M:%SZ"))
    except SyncError as exc:
        print(f"spec-sync: {exc}", file=sys.stderr)
        return 1
    except SpecError as exc:
        print(f"spec-sync: {exc}", file=sys.stderr)
        return 2

    if not result.files:
        print("No upstream change")
        return 0

    try:
        validate(root, result.files)
    except SpecError as exc:
        print(f"spec-sync: the synced documents cannot be generated, nothing was written to openapi/.\n{exc}", file=sys.stderr)
        if args.summary_file is not None:
            args.summary_file.write_text(f"{result.summary}\n## Action needed\n\n```text\n{exc}\n```\n", "utf-8")
        return 2

    write(root, result.files)
    summary = result.summary
    if args.summary_file is not None:
        args.summary_file.write_text(summary, "utf-8")
    else:
        sys.stdout.write(summary)
    if args.commit_message_file is not None:
        args.commit_message_file.write_text(result.report.commit_message, "utf-8")
    changed = ", ".join(result.upstream_changed) or "no upstream file"
    written = ", ".join(sorted(result.files))
    print(f"spec-sync: {changed} changed; updated {written}", file=sys.stderr if args.summary_file is None else sys.stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
