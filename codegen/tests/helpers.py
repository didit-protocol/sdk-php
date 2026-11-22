"""Shared helpers for the generator tests: import path and tiny specs in temp dirs."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[2]
CODEGEN = REPO / "codegen"
if str(CODEGEN) not in sys.path:
    sys.path.insert(0, str(CODEGEN))


def naming(resource: str, method: str, **extra: Any) -> dict[str, Any]:
    out: dict[str, Any] = {"resource": resource, "method": method, "auth": "api_key", "server": True}
    out.update(extra)
    return out


def document(paths: dict[str, Any], schemas: dict[str, Any] | None = None) -> dict[str, Any]:
    doc: dict[str, Any] = {"openapi": "3.0.0", "info": {"title": "test", "version": "1"}, "paths": paths}
    if schemas:
        doc["components"] = {"schemas": schemas}
    return doc


def ok(schema: dict[str, Any] | None = None, status: str = "200") -> dict[str, Any]:
    """A ``responses`` object with one JSON response."""
    if schema is None:
        return {status: {"description": "ok"}}
    return {status: {"description": "ok", "content": {"application/json": {"schema": schema}}}}


def write_openapi(
    root: Path,
    verification: dict[str, Any],
    overlay: dict[str, Any],
    account: dict[str, Any] | None = None,
    deprecated: list[dict[str, Any]] | None = None,
) -> None:
    openapi = root / "openapi"
    openapi.mkdir(parents=True, exist_ok=True)
    (openapi / "verification.json").write_text(json.dumps(verification, indent=2) + "\n", "utf-8")
    (openapi / "account.json").write_text(json.dumps(account or document({}), indent=2) + "\n", "utf-8")
    (openapi / "overlay.json").write_text(json.dumps({"operations": overlay}, indent=1), "utf-8")
    if deprecated is not None:
        (openapi / "deprecated-operations.json").write_text(json.dumps({"operations": deprecated}, indent=1), "utf-8")


# A small spec covering the type naming rules of docs/DESIGN.md section 5.1.
SESSIONS_SCHEMAS: dict[str, Any] = {
    "SessionDecision": {
        "type": "object",
        "properties": {
            "session_id": {"type": "string", "format": "uuid"},
            "status": {"type": "string", "enum": ["Approved", "Declined", "In Review"]},
            "created_at": {"type": "string", "format": "date-time"},
        },
    },
}

SESSIONS_PATHS: dict[str, Any] = {
    "/v3/session/": {
        "post": {
            "summary": "Create a session",
            "requestBody": {
                "required": True,
                "content": {
                    "application/json": {
                        "schema": {
                            "type": "object",
                            "required": ["workflow_id"],
                            "properties": {
                                "workflow_id": {"type": "string"},
                                "contact_details": {
                                    "type": "object",
                                    "properties": {"email": {"type": "string", "format": "email"}},
                                },
                                "metadata": {"description": "Free-form", "example": {"plan": "gold"}},
                            },
                        }
                    }
                },
            },
            "responses": ok({
                "type": "object",
                "properties": {
                    "session_id": {"type": "string"},
                    "decision": {"$ref": "#/components/schemas/SessionDecision"},
                },
            }, "201"),
        }
    },
    "/v3/session/{sessionId}/decision/": {
        "get": {
            "summary": "Retrieve a decision",
            "parameters": [{"name": "sessionId", "in": "path", "required": True, "schema": {"type": "string"}}],
            "responses": ok({"$ref": "#/components/schemas/SessionDecision"}),
        }
    },
}

SESSIONS_OVERLAY: dict[str, Any] = {
    "POST /v3/session/": naming("sessions", "create"),
    "GET /v3/session/{sessionId}/decision/": naming("sessions", "retrieve_decision"),
}


def write_sessions(root: Path, deprecated: list[dict[str, Any]] | None = None) -> None:
    write_openapi(root, document(SESSIONS_PATHS, SESSIONS_SCHEMAS), SESSIONS_OVERLAY, deprecated=deprecated)
