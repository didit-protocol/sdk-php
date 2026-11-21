"""Loading openapi/ and validating the SDK naming (docs/DESIGN.md sections 3.1 and 4)."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from typing import Any

from helpers import document, naming, ok, write_openapi
from didit_codegen import spec

APP = "/v3/organization/{organization_id}/application/{application_id}"


def _load(paths: dict[str, Any], overlay: dict[str, Any], account: dict[str, Any] | None = None) -> spec.Spec:
    with tempfile.TemporaryDirectory() as tmp:
        write_openapi(Path(tmp), document(paths), overlay, account)
        return spec.load(Path(tmp))


def _get(summary: str = "op") -> dict[str, Any]:
    return {"get": {"summary": summary, "responses": ok()}}


class MissingNamingTest(unittest.TestCase):
    def test_a_new_operation_without_naming_names_the_operation(self) -> None:
        paths = {"/v3/session/": _get(), "/v3/new-thing/": {"post": {"responses": ok()}}}
        account = document({"/organizations/new/": _get()})
        with self.assertRaises(spec.SpecError) as ctx:
            _load(paths, {"GET /v3/session/": naming("sessions", "list")}, account)
        message = str(ctx.exception)
        self.assertIn("have no SDK naming (x-didit-sdk)", message)
        self.assertIn("openapi/overlay.json", message)
        self.assertIn("  - POST /v3/new-thing/", message)
        self.assertIn("  - account GET /organizations/new/", message)
        self.assertNotIn("GET /v3/session/\n", message)

    def test_upstream_naming_wins_over_the_overlay(self) -> None:
        paths = {"/v3/session/": {"get": {"responses": ok(), "x-didit-sdk": naming("sessions", "list_all")}}}
        s = _load(paths, {"GET /v3/session/": naming("sessions", "list")})
        self.assertEqual(s.operations[0].naming.method, "list_all")


class ValidationTest(unittest.TestCase):
    def assertInvalid(self, paths: dict[str, Any], overlay: dict[str, Any], fragment: str) -> None:
        with self.assertRaises(spec.SpecError) as ctx:
            _load(paths, overlay)
        self.assertIn("Invalid SDK naming", str(ctx.exception))
        self.assertIn(fragment, str(ctx.exception))

    def test_duplicate_resource_method(self) -> None:
        self.assertInvalid(
            {"/v3/a/": _get(), "/v3/b/": _get()},
            {"GET /v3/a/": naming("things", "list"), "GET /v3/b/": naming("things", "list")},
            "GET /v3/b/: things.list is already used by GET /v3/a/",
        )

    def test_application_scope_needs_the_prefix(self) -> None:
        self.assertInvalid(
            {"/v3/cases/": _get()},
            {"GET /v3/cases/": naming("cases", "list", scope="application")},
            "scope application needs a path starting with /v3/organization/{organization_id}/application/{application_id}/",
        )

    def test_application_path_needs_the_scope(self) -> None:
        self.assertInvalid(
            {f"{APP}/cases/": _get()},
            {f"GET {APP}/cases/": naming("cases", "list")},
            'an application-scoped path needs scope "application"',
        )

    def test_method_clashing_with_a_sub_resource(self) -> None:
        self.assertInvalid(
            {"/v3/session/imports/": _get(), "/v3/session/imports/new/": {"post": {"responses": ok()}}},
            {
                "GET /v3/session/imports/": naming("sessions", "imports"),
                "POST /v3/session/imports/new/": naming("sessions.imports", "create"),
            },
            "method imports clashes with the sub-resource sessions.imports",
        )

    def test_server_operation_with_device_token_auth(self) -> None:
        self.assertInvalid(
            {"/v3/sdk/": _get()},
            {"GET /v3/sdk/": naming("sdk", "retrieve", auth="device_token")},
            "server operations cannot use auth 'device_token'; set server to false",
        )

    def test_device_token_operations_are_fine_when_not_server(self) -> None:
        s = _load({"/v3/sdk/": _get()}, {"GET /v3/sdk/": naming("sdk", "retrieve", auth="device_token", server=False)})
        self.assertEqual(s.server_operations, [])

    def test_malformed_naming(self) -> None:
        with self.assertRaises(spec.SpecError) as ctx:
            _load({"/v3/a/": _get()}, {"GET /v3/a/": naming("Things", "list")})
        self.assertIn("x-didit-sdk.resource must be a dot path of snake_case names", str(ctx.exception))


if __name__ == "__main__":
    unittest.main()
