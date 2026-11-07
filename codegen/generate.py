#!/usr/bin/env python3
"""Regenerates the SDK's generated layer from openapi/.

Usage:
    python3 codegen/generate.py          write the generated files
    python3 codegen/generate.py --check  fail when the committed files differ
"""

from __future__ import annotations

import argparse
import difflib
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from didit_codegen.generate import build, existing_generated  # noqa: E402
from didit_codegen.spec import SpecError  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--check", action="store_true", help="compare instead of writing")
    parser.add_argument("--verbose", action="store_true", help="print naming notes")
    args = parser.parse_args()

    try:
        files, notes = build(ROOT)
    except SpecError as exc:
        print(f"codegen: {exc}", file=sys.stderr)
        return 2

    if args.verbose:
        for note in notes:
            print(f"note: {note}")

    stale = sorted(existing_generated(ROOT) - set(files))
    if args.check:
        problems = []
        for rel, content in sorted(files.items()):
            path = ROOT / rel
            current = path.read_text("utf-8") if path.exists() else None
            if current != content:
                problems.append(rel)
                if current is not None and len(problems) <= 5:
                    diff = difflib.unified_diff(current.splitlines(), content.splitlines(), f"a/{rel}", f"b/{rel}", lineterm="", n=2)
                    print("\n".join(list(diff)[:40]))
        problems.extend(f"{s} (stale, no longer generated)" for s in stale)
        if problems:
            print(f"codegen: {len(problems)} generated file(s) are out of date. Run `python3 codegen/generate.py` and commit the result:", file=sys.stderr)
            for p in problems[:50]:
                print(f"  - {p}", file=sys.stderr)
            return 1
        print(f"codegen: {len(files)} generated files are up to date.")
        return 0

    written = 0
    for rel, content in files.items():
        path = ROOT / rel
        if path.exists() and path.read_text("utf-8") == content:
            continue
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, "utf-8")
        written += 1
    for rel in stale:
        (ROOT / rel).unlink()
    print(f"codegen: {len(files)} files ({written} written, {len(stale)} removed).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
