"""Type model and PHP emitters (docs/DESIGN.md section 5.1)."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from helpers import write_sessions
from didit_codegen import model, php, spec
from didit_codegen.model import AnyT, ArrayT, FreeObjT, MapT, Prop, Scalar, Shape, ShapeT


class TypeNamingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            write_sessions(Path(tmp))
            s = spec.load(Path(tmp))
        cls.reg = model.Registry()
        cls.ops = {o.op.naming.method: o for o in model.build(cls.reg, s.server_operations)}

    def test_a_component_keeps_its_name(self) -> None:
        self.assertIn("SessionDecision", self.reg.models)
        response = self.ops["retrieve_decision"].response
        self.assertEqual(response.kind, "model")
        self.assertEqual(response.type.model.name, "SessionDecision")

    def test_inline_body_is_resource_method_params(self) -> None:
        create = self.ops["create"]
        self.assertEqual(create.body.name, "SessionsCreateParams")
        self.assertEqual(create.body_alias, "SessionsCreateParams")
        self.assertIn("SessionsCreateParams", self.reg.shapes)

    def test_inline_response_is_resource_method_response(self) -> None:
        response = self.ops["create"].response
        self.assertEqual(response.status, "201")
        self.assertEqual(response.type.model.name, "SessionsCreateResponse")

    def test_nested_inline_object_appends_the_property_name(self) -> None:
        self.assertIn("SessionsCreateParamsContactDetails", self.reg.shapes)
        contact = next(p for p in self.ops["create"].body.props if p.wire == "contact_details")
        self.assertEqual(contact.type.shape.name, "SessionsCreateParamsContactDetails")

    def test_enums_are_open_string_constants(self) -> None:
        enum = self.reg.enums["SessionDecisionStatus"]
        self.assertEqual(enum.base, "string")
        source = php.emit_enum(enum)
        self.assertIn("final class SessionDecisionStatus", source)
        self.assertIn("public const APPROVED = 'Approved';", source)
        self.assertIn("public const IN_REVIEW = 'In Review';", source)
        self.assertIn("The API may add values", source)
        self.assertNotIn("enum SessionDecisionStatus", source)
        # The model property is a plain string, so an unknown value never raises.
        model_source = php.emit_model(self.reg.models["SessionDecision"])
        self.assertIn("public readonly ?string $status;", model_source)
        self.assertIn("Known values: {@see SessionDecisionStatus}.", model_source)
        self.assertIn("public readonly ?\\DateTimeImmutable $createdAt;", model_source)


class JsonHintTest(unittest.TestCase):
    def _shape(self, *props: Prop) -> ShapeT:
        return ShapeT(Shape("Test", "", list(props)))

    def test_object_fields_get_object_hints(self) -> None:
        nested = self._shape(Prop("email", "email", Scalar("string"), False, ""))
        t = self._shape(
            Prop("name", "name", Scalar("string"), True, ""),
            Prop("contact", "contact", nested, False, ""),
            Prop("meta", "meta", FreeObjT(), False, ""),
            Prop("tags", "tags", MapT(Scalar("string")), False, ""),
        )
        self.assertEqual(php.json_hint(t), ["o", {"contact": ["o"], "meta": ["o"], "tags": ["o"]}])

    def test_any_typed_field_with_an_object_example_gets_an_object_hint(self) -> None:
        reg = model.Registry()
        doc = spec.Document("verification", {})
        with_object = reg.convert(doc, {"description": "x", "example": {"plan": "gold"}}, "H", "request", "o1")
        with_string = reg.convert(doc, {"example": "gold"}, "H", "request", "o2")
        self.assertIsInstance(with_object, AnyT)
        self.assertEqual(php.json_hint(with_object), ["o"])
        self.assertIsNone(php.json_hint(with_string))

    def test_list_of_objects(self) -> None:
        item = self._shape(Prop("meta", "meta", FreeObjT(), False, ""))
        self.assertEqual(php.json_hint(ArrayT(item)), ["l", ["o", {"meta": ["o"]}]])
        self.assertIsNone(php.json_hint(ArrayT(Scalar("string"))))

    def test_hint_reaches_the_generated_operation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            write_sessions(Path(tmp))
            s = spec.load(Path(tmp))
        reg = model.Registry()
        ops = model.build(reg, s.server_operations)
        source = php.emit_resource(php.walk(php.resource_tree(ops)).__next__())
        self.assertIn("jsonHints: ['contact_details' => ['o'], 'metadata' => ['o']]", source)


if __name__ == "__main__":
    unittest.main()
