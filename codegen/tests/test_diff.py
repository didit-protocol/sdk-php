"""Operation-level change detection for the spec sync summary (docs/DESIGN.md section 3.1)."""

from __future__ import annotations

import copy
import json
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


def _changes(mutate: Any, before: Any = None) -> list[diff.OpChange]:
    """``before`` edits both versions, ``mutate`` only the new one."""
    old = document(copy.deepcopy(BASE_PATHS), copy.deepcopy(BASE_SCHEMAS))
    if before is not None:
        before(old)
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
        self.assertEqual(_text(change), [])

    def test_a_property_named_like_a_doc_key_is_structural(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["description"] = {"type": "string"}
        [change] = _changes(mutate)
        self.assertFalse(change.docs_only)
        self.assertEqual(change.notes, ["response 200 `description`: field added"])


def _text(change: diff.OpChange) -> list[str]:
    return [str(b) for b in change.breaking]


class BreakingTest(unittest.TestCase):
    def _breaking(self, mutate: Any) -> list[str]:
        [change] = _changes(mutate)
        return _text(change)

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
        self.assertEqual(_text(change), ["response 200 `status`: enum value `Declined` removed"])
        self.assertEqual(change.notes, ["response 200 `status`: enum value `In Review` added"])

    def test_request_fields(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            body = _body(d)
            body["required"] = ["workflow_id", "vendor_data", "callback"]
            body["properties"]["callback"] = {"type": "string"}
            body["properties"]["language"] = {"type": "string"}
            del body["properties"]["workflow_id"]
        [change] = _changes(mutate)
        self.assertEqual(_text(change), [
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
        self.assertEqual(_text(change), [
            "query parameter `dry_run`: removed",
            "query parameter `region`: new required parameter",
        ])

    def test_response_content_type(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            _post(d)["responses"] = {"200": {"description": "pdf", "content": {"application/pdf": {}}}}
        self.assertEqual(self._breaking(mutate), ["response 200: content type changed from application/json to application/pdf"])


class NowNullTest(unittest.TestCase):
    """A field or parameter that can now be null breaks typed code (DESIGN 3.1), in a response or
    a request, in every form the generator reads."""

    @staticmethod
    def _breaking(mutate: Any, before: Any = None) -> list[tuple[str, str, str]]:
        [change] = _changes(mutate, before)
        return [(b.where, b.field, b.kind) for b in change.breaking]

    def test_nullable_true(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["score"]["nullable"] = True
        self.assertEqual(self._breaking(mutate), [("response 200", "score", "now-null")])
        self.assertEqual([str(b) for b in _changes(mutate)[0].breaking], ["response 200 `score`: can now be null"])

    def test_nullable_through_a_ref(self) -> None:
        # `registry_data` on 2026-11-04: an inline object became a $ref to a nullable schema.
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Score"] = {"type": "integer", "nullable": True}
            d["components"]["schemas"]["Decision"]["properties"]["score"] = {"$ref": "#/components/schemas/Score"}
        self.assertEqual(self._breaking(mutate), [("response 200", "score", "now-null")])

    def test_openapi_3_1_forms(self) -> None:
        forms: list[dict[str, Any]] = [
            {"type": ["integer", "null"]},
            {"oneOf": [{"type": "integer"}, {"type": "null"}]},
            {"anyOf": [{"type": "null"}, {"type": "integer"}]},
            {"allOf": [{"type": "integer", "nullable": True}]},
        ]
        for form in forms:
            with self.subTest(form=form):
                def mutate(d: dict[str, Any]) -> None:
                    d["components"]["schemas"]["Decision"]["properties"]["score"] = copy.deepcopy(form)
                self.assertEqual(self._breaking(mutate), [("response 200", "score", "now-null")])

    def test_a_request_field_or_parameter_that_can_now_be_null(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            _body(d)["properties"]["vendor_data"]["nullable"] = True
            _post(d)["parameters"][0]["schema"] = {"type": ["boolean", "null"]}
        [change] = _changes(mutate)
        self.assertEqual([str(b) for b in change.breaking], [
            "query parameter `dry_run`: can now be null",
            "request body `vendor_data`: can now be null",
        ])

    def test_a_schema_shared_by_two_fields_is_reported_at_both(self) -> None:
        # `email_intelligence.reason_codes[]` and `enrichment.reason_codes[]` on 2026-11-04.
        def before(d: dict[str, Any]) -> None:
            props = d["components"]["schemas"]["Decision"]["properties"]
            props["first"] = {"type": "array", "items": {"type": "string"}}
            props["second"] = {"type": "array", "items": {"type": "string"}}
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Reason"] = {"type": "object", "properties": {"code": {"type": "string"}}}
            for name in ("first", "second"):
                d["components"]["schemas"]["Decision"]["properties"][name]["items"] = {"$ref": "#/components/schemas/Reason"}
        self.assertEqual(self._breaking(mutate, before), [
            ("response 200", "first[]", "string->object"),
            ("response 200", "second[]", "string->object"),
        ])

    def test_the_same_change_in_two_success_responses_is_one_entry(self) -> None:
        def before(d: dict[str, Any]) -> None:
            _post(d)["responses"]["202"] = ok({"$ref": "#/components/schemas/Decision"})["200"]
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["score"]["nullable"] = True
        [change] = _changes(mutate, before)
        self.assertEqual([str(b) for b in change.breaking], ["response 200, 202 `score`: can now be null"])
        self.assertEqual([(b.field, b.kind) for b in change.breaking], [("score", "now-null")])

    def test_extension_keys_are_documentation(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Decision"]["properties"]["status"]["x-readme-id"] = "status"
        [change] = _changes(mutate)
        self.assertTrue(change.docs_only)


class TypeNameTest(unittest.TestCase):
    """The generator names a type after its component schema, so a field that now uses a differently
    named component changes a public type even when the content is the same."""

    @staticmethod
    def _rename(d: dict[str, Any], old: str, new: str) -> None:
        schemas = d["components"]["schemas"]
        schemas[new] = schemas.pop(old)
        text = json.dumps(d).replace(f'"#/components/schemas/{old}"', f'"#/components/schemas/{new}"')
        d.clear()
        d.update(json.loads(text))

    def test_a_renamed_component_changes_the_type(self) -> None:
        [change] = _changes(lambda d: self._rename(d, "Decision", "DecisionV2"))
        self.assertFalse(change.docs_only)
        self.assertEqual(change.parts, ["response 200"])
        self.assertEqual([(b.where, b.field, b.kind) for b in change.breaking], [("response 200", "", "Decision->DecisionV2")])
        self.assertEqual([str(b) for b in change.breaking], ["response 200: type changed from Decision to DecisionV2"])

    def test_a_renamed_component_below_a_field_and_in_a_request(self) -> None:
        def before(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Reason"] = {"type": "string", "enum": ["a", "b"]}
            d["components"]["schemas"]["Decision"]["properties"]["reasons"] = {
                "type": "array", "items": {"$ref": "#/components/schemas/Reason"},
            }
            _body(d)["properties"]["reason"] = {"allOf": [{"$ref": "#/components/schemas/Reason"}]}
        [change] = _changes(lambda d: self._rename(d, "Reason", "ReasonCode"), before)
        self.assertEqual([str(b) for b in change.breaking], [
            "request body `reason`: type changed from Reason to ReasonCode",
            "response 200 `reasons[]`: type changed from Reason to ReasonCode",
        ])

    def test_a_renamed_component_that_names_no_type_changes_nothing(self) -> None:
        # A string alias generates no class, so its name is not part of the SDK.
        def before(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["Score"] = {"type": "integer"}
            d["components"]["schemas"]["Decision"]["properties"]["score"] = {"$ref": "#/components/schemas/Score"}
        self.assertEqual(_changes(lambda d: self._rename(d, "Score", "Points"), before), [])

    def test_an_inline_schema_that_becomes_a_component(self) -> None:
        def mutate(d: dict[str, Any]) -> None:
            d["components"]["schemas"]["SessionRequest"] = _body(d)
            _post(d)["requestBody"]["content"]["application/json"]["schema"] = {"$ref": "#/components/schemas/SessionRequest"}
        [change] = _changes(mutate)
        self.assertFalse(change.docs_only)
        self.assertEqual(change.breaking, [])
        self.assertEqual(change.notes, ["request body: now the type `SessionRequest`"])


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
