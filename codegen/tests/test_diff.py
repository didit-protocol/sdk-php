"""Operation-level change detection for the spec sync summary (docs/DESIGN.md section 3.1)."""

from __future__ import annotations

import copy
import unittest
from typing import Any

from helpers import document, ok
from didit_codegen import diff
from didit_codegen.spec import Document

BASE_SCHEMAS: dict[str, Any] = {
    "Decision": {
        "type": "object",
        "properties": {
            "status": {"type": "string", "enum": ["Approved", "Declined"], "description": "Status."},
            "score": {"type": "integer"},
        },
    },
}
BASE_PATHS: dict[str, Any] = {
    "/v3/session/": {
        "post": {
            "parameters": [{"name": "dry_run", "in": "query", "schema": {"type": "boolean"}}],
            "requestBody": {"content": {"application/json": {"schema": {
                "type": "object",
                "required": ["workflow_id"],
                "properties": {"workflow_id": {"type": "string"}, "vendor_data": {"type": "string"}},
            }}}},
            "responses": ok({"$ref": "#/components/schemas/Decision"}),
        }
    },
    "/v3/old/": {"get": {"responses": ok()}},
}


def _changes(mutate: Any) -> list[diff.OpChange]:
    old = document(copy.deepcopy(BASE_PATHS), copy.deepcopy(BASE_SCHEMAS))
    new = copy.deepcopy(old)
    mutate(new)
    return diff.changed_operations(Document("verification", old), Document("verification", new), "")


def _post(doc: dict[str, Any]) -> dict[str, Any]:
    return doc["paths"]["/v3/session/"]["post"]


def _body(doc: dict[str, Any]) -> dict[str, Any]:
    return _post(doc)["requestBody"]["content"]["application/json"]["schema"]


class OperationsTest(unittest.TestCase):
    def test_keys_match_spec_load(self) -> None:
        ops = diff.operations(Document("account", document(BASE_PATHS)), "account ")
        self.assertEqual(sorted(ops), ["account GET /v3/old/", "account POST /v3/session/"])

    def test_unchanged(self) -> None:
        self.assertEqual(_changes(lambda d: None), [])

    def test_documentation_only(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["status"]["description"] = "The status."
        [change] = _changes(mutate)
        self.assertEqual(change.parts, ["response 200"])
        self.assertTrue(change.docs_only)
        self.assertEqual(change.breaking, [])

    def test_a_property_named_like_a_doc_key_is_structural(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["description"] = {"type": "string"}
        [change] = _changes(mutate)
        self.assertFalse(change.docs_only)
        self.assertEqual(change.notes, ["response 200 `description`: field added"])


class BreakingTest(unittest.TestCase):
    def _breaking(self, mutate: Any) -> list[str]:
        [change] = _changes(mutate)
        return change.breaking

    def test_removed_response_field(self) -> None:
        self.assertEqual(
            self._breaking(lambda d: d["components"]["schemas"]["Decision"]["properties"].pop("score")),
            ["response 200 `score`: field removed"],
        )

    def test_changed_type(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["score"] = {"type": "string"}
        self.assertEqual(self._breaking(mutate), ["response 200 `score`: type changed from integer to string"])

    def test_removed_enum_value(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["status"]["enum"] = ["Approved", "In Review"]
        [change] = _changes(mutate)
        self.assertEqual(change.breaking, ["response 200 `status`: enum value `Declined` removed"])
        self.assertEqual(change.notes, ["response 200 `status`: enum value `In Review` added"])

    def test_request_fields(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            body = _body(d)
            body["required"] = ["workflow_id", "vendor_data", "callback"]
            body["properties"]["callback"] = {"type": "string"}
            body["properties"]["language"] = {"type": "string"}
            del body["properties"]["workflow_id"]
        [change] = _changes(mutate)
        self.assertEqual(change.breaking, [
            "request body `workflow_id`: field removed",
            "request body `vendor_data`: now required",
            "request body `callback`: new required field",
        ])
        self.assertEqual(change.notes, ["request body `language`: field added"])

    def test_parameters(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            _post(d)["parameters"] = [{"name": "region", "in": "query", "required": True, "schema": {"type": "string"}}]
        [change] = _changes(mutate)
        self.assertEqual(change.parts, ["parameters"])
        self.assertEqual(change.breaking, [
            "query parameter `dry_run`: removed",
            "query parameter `region`: new required parameter",
        ])

    def test_response_content_type(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            _post(d)["responses"] = {"200": {"description": "pdf", "content": {"application/pdf": {}}}}
        self.assertEqual(self._breaking(mutate), ["response 200: content type changed from application/json to application/pdf"])


class SchemaChangesTest(unittest.TestCase):
    def test_added_removed_changed(self) -> None:
        old = Document("verification", document({}, {"A": {"type": "string"}, "B": {"type": "string"}, "C": {"type": "string"}}))
        new = Document("verification", document({}, {
            "B": {"type": "integer"}, "C": {"type": "string", "description": "c"}, "D": {"type": "string"},
        }))
        sc = diff.schema_changes(old, new)
        self.assertEqual(sc.added, ["D"])
        self.assertEqual(sc.removed, ["A"])
        self.assertEqual(sc.changed, [("B", False), ("C", True)])


if __name__ == "__main__":
    unittest.main()
