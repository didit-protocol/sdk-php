#!/usr/bin/env python3
"""Updates the open spec sync pull request when a sync is pushed on top of
commits from people (docs/DESIGN.md section 3.1).

Usage:
    python3 codegen/sync_pull_request.py --previous PR.json --summary SUMMARY.md --out DIR

PR.json is the output of `gh pr view --json title,body`; SUMMARY.md is what
codegen/spec_sync.py wrote for this sync. Writes DIR/title.txt and DIR/body.md,
the pull request title and description, whose BEGIN_COMMIT_OVERRIDE block now
also carries this sync's release lines (feat wins over chore), and
DIR/comment.md, this sync's summary.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from didit_codegen.sync import continue_pull_request  # noqa: E402


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--previous", type=Path, required=True, help="the pull request as `gh pr view --json title,body` prints it")
    parser.add_argument("--summary", type=Path, required=True, help="the summary codegen/spec_sync.py wrote")
    parser.add_argument("--out", type=Path, required=True, help="directory for title.txt, body.md and comment.md")
    args = parser.parse_args(argv)

    previous = json.loads(args.previous.read_text("utf-8"))
    try:
        title, body, comment = continue_pull_request(
            str(previous.get("title") or ""), str(previous.get("body") or ""), args.summary.read_text("utf-8"),
        )
    except ValueError as exc:
        print(f"sync-pull-request: {exc}", file=sys.stderr)
        return 1
    args.out.mkdir(parents=True, exist_ok=True)
    (args.out / "title.txt").write_text(title + "\n", "utf-8")
    (args.out / "body.md").write_text(body, "utf-8")
    (args.out / "comment.md").write_text(comment, "utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
