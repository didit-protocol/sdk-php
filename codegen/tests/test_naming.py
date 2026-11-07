"""Name conversions (docs/DESIGN.md sections 4 and 5.1)."""

from __future__ import annotations

import unittest

import helpers  # noqa: F401  (import path)
from didit_codegen import naming


class PascalCamelTest(unittest.TestCase):
    def test_pascal(self) -> None:
        self.assertEqual(naming.pascal("id_verification"), "IdVerification")
        self.assertEqual(naming.pascal("sessions.imports"), "SessionsImports")
        self.assertEqual(naming.pascal("x-api-key"), "XApiKey")
        self.assertEqual(naming.pascal("contactDetails"), "ContactDetails")  # inner capitals kept
        self.assertEqual(naming.pascal(""), "")

    def test_camel(self) -> None:
        self.assertEqual(naming.camel("session_id"), "sessionId")
        self.assertEqual(naming.camel("retrieve_decision"), "retrieveDecision")
        self.assertEqual(naming.camel("id"), "id")
        self.assertEqual(naming.camel(""), "")

    def test_camel_lowers_a_leading_acronym(self) -> None:
        self.assertEqual(naming.camel("URL"), "url")
        self.assertEqual(naming.camel("URLPath"), "urlPath")
        self.assertEqual(naming.camel("IDNumber"), "idNumber")
        self.assertEqual(naming.camel("AML_screening"), "amlScreening")


class PropertyAndClassNameTest(unittest.TestCase):
    def test_property_name(self) -> None:
        self.assertEqual(naming.property_name("first_name"), "firstName")
        self.assertEqual(naming.property_name("2fa_enabled"), "field2faEnabled")
        self.assertEqual(naming.property_name(""), "value")
        self.assertEqual(naming.property_name("-"), "value")

    def test_class_name_suffixes_reserved_words(self) -> None:
        self.assertEqual(naming.class_name("session_decision"), "SessionDecision")
        self.assertEqual(naming.class_name("list"), "ListModel")
        self.assertEqual(naming.class_name("class"), "ClassModel")
        self.assertEqual(naming.class_name("string"), "StringModel")
        self.assertEqual(naming.class_name("Match"), "MatchModel")
        self.assertEqual(naming.class_name("3d_secure"), "Type3dSecure")

    def test_resource_paths(self) -> None:
        self.assertEqual(naming.resource_class("cases.routing_rules"), "Cases\\RoutingRules")
        self.assertEqual(naming.type_prefix("sessions.imports", "create"), "SessionsImportsCreate")


class EnumConstantTest(unittest.TestCase):
    def test_spellings_map_to_one_constant_and_stay_unique(self) -> None:
        used: set[str] = set()
        self.assertEqual(naming.enum_constant("in_review", used), "IN_REVIEW")
        self.assertEqual(naming.enum_constant("In Review", used), "IN_REVIEW_2")
        self.assertEqual(naming.enum_constant("inReview", used), "IN_REVIEW_3")
        self.assertEqual(used, {"IN_REVIEW", "IN_REVIEW_2", "IN_REVIEW_3"})

    def test_edge_values(self) -> None:
        used: set[str] = set()
        self.assertEqual(naming.enum_constant("", used), "EMPTY")
        self.assertEqual(naming.enum_constant("1st", used), "VALUE_1ST")
        self.assertEqual(naming.enum_constant(5, used), "VALUE_5")
        self.assertEqual(naming.enum_constant("class", used), "CLASS_")
        self.assertEqual(naming.enum_constant("pending-review", used), "PENDING_REVIEW")


if __name__ == "__main__":
    unittest.main()
