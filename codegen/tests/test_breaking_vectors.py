"""The shared breaking-change vectors, openapi/breaking-vectors.json (docs/DESIGN.md section 3.1).

sdk-python generates the file and the other six server SDKs copy it byte for byte.
Every case runs through this repository's spec sync and none is skipped: a case
that fails is fixed in the detector, never in the file.
"""

from __future__ import annotations

import hashlib
import json
import re
import unittest
from typing import Any

from helpers import REPO
from didit_codegen.sync import classify

VECTORS_PATH = REPO / "openapi" / "breaking-vectors.json"
VECTORS: Any = json.loads(VECTORS_PATH.read_text("utf-8"))
# The rule of a case -> how its bullet opens in DESIGN.md section 3.1.
RULES = {
    "Responses": "- Responses (",
    "Requests": "- Requests (",
    "Operations": "- Operations:",
    "Unions": "- Unions (",
    "Success statuses": "- Success statuses:",
    "Release notes": "- Every counted change reaches the release notes",
}
# The Authentication module is hand-written and has exactly the document's methods
# (DESIGN 10 and 11): no deprecated alias keeps a renamed method's old name, so it goes.
HAND_WRITTEN = {"renamed-method-is-listed": ["GET /things/{id}/: operation removed"]}


class BreakingVectorsTest(unittest.TestCase):
    def test_the_file_is_the_copy_source_json_records(self) -> None:
        source = json.loads((REPO / "openapi" / "SOURCE.json").read_text("utf-8"))["breaking-vectors.json"]
        self.assertEqual(hashlib.sha256(VECTORS_PATH.read_bytes()).hexdigest(), source["sha256"])
        self.assertEqual(list(VECTORS), ["version", "rules", "generator", "cases"])
        self.assertEqual((VECTORS["version"], VECTORS["rules"]), (1, "docs/DESIGN.md section 3.1"))

    def test_every_rule_is_a_design_bullet_with_cases(self) -> None:
        design = (REPO / "docs" / "DESIGN.md").read_text("utf-8")
        section = design.split("### 3.1 Spec sync", 1)[1].split("\n## ", 1)[0]
        rules = [case["rule"] for case in VECTORS["cases"]]
        self.assertEqual(set(rules), set(RULES))
        for rule, opening in RULES.items():
            self.assertIn(f"\n{opening}", section, rule)
            self.assertGreaterEqual(rules.count(rule), 2, rule)

    def test_names_are_unique_and_lines_sorted(self) -> None:
        names = [case["name"] for case in VECTORS["cases"]]
        self.assertEqual(len(names), len(set(names)))
        for case in VECTORS["cases"]:
            self.assertRegex(case["name"], re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$"))
            self.assertEqual(case["expect"]["breaking"], sorted(set(case["expect"]["breaking"])), case["name"])

    def test_every_case(self) -> None:
        for case in VECTORS["cases"]:
            with self.subTest(case=case["name"]):
                expect = case["expect"]
                self.assertEqual(classify(case["before"], case["after"]), (expect["type"], expect["breaking"]))

    def test_every_case_in_the_authentication_document(self) -> None:
        for case in VECTORS["cases"]:
            with self.subTest(case=case["name"]):
                expect = case["expect"]
                breaking = sorted(f"authentication {line}" for line in HAND_WRITTEN.get(case["name"], expect["breaking"]))
                self.assertEqual(classify(case["before"], case["after"], "authentication.json"), (expect["type"], breaking))


if __name__ == "__main__":
    unittest.main()
