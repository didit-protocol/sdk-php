"""Loads ``openapi/`` and joins every operation with its SDK naming."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from . import naming

HTTP_METHODS = ("get", "post", "put", "patch", "delete")
AUTH_VALUES = {"api_key", "account_token", "none", "device_token", "public"}
APPLICATION_PREFIX = "/v3/organization/{organization_id}/application/{application_id}/"


class SpecError(Exception):
    """The spec or the overlay cannot be turned into an SDK."""


@dataclass
class Document:
    name: str  # "verification" or "account"
    data: dict[str, Any]

    def resolve(self, ref: str) -> Any:
        if not ref.startswith("#/"):
            raise SpecError(f"{self.name}: only local $ref values are supported, got {ref}")
        node: Any = self.data
        for part in ref[2:].split("/"):
            part = part.replace("~1", "/").replace("~0", "~")
            try:
                node = node[part]
            except (KeyError, TypeError) as exc:
                raise SpecError(f"{self.name}: unresolvable $ref {ref}") from exc
        return node

    def deref(self, node: Any) -> Any:
        seen = 0
        while isinstance(node, dict) and "$ref" in node:
            node = self.resolve(node["$ref"])
            seen += 1
            if seen > 50:
                raise SpecError(f"{self.name}: $ref loop")
        return node


@dataclass
class Naming:
    resource: str
    method: str
    auth: str
    server: bool
    scope: str | None


@dataclass
class Operation:
    key: str  # "POST /v3/session/" or "account GET /organizations/me/"
    doc: Document
    http_method: str  # upper case
    path: str
    op: dict[str, Any]
    path_item: dict[str, Any]
    naming: Naming
    deprecated: bool = False
    removed_note: str | None = None
    renamed_to: Naming | None = None  # set on the deprecated alias of a renamed operation
    parameters: list[dict[str, Any]] = field(default_factory=list)

    @property
    def base(self) -> str:
        return self.doc.name


def _naming_from(raw: Any, key: str) -> Naming:
    if not isinstance(raw, dict):
        raise SpecError(f"{key}: x-didit-sdk must be an object")
    resource = raw.get("resource")
    method = raw.get("method")
    auth = raw.get("auth")
    server = raw.get("server")
    scope = raw.get("scope")
    if not isinstance(resource, str) or not naming.RESOURCE_RE.match(resource):
        raise SpecError(f"{key}: x-didit-sdk.resource must be a dot path of snake_case names, got {resource!r}")
    if not isinstance(method, str) or not naming.METHOD_RE.match(method):
        raise SpecError(f"{key}: x-didit-sdk.method must be snake_case, got {method!r}")
    if auth not in AUTH_VALUES:
        raise SpecError(f"{key}: x-didit-sdk.auth must be one of {sorted(AUTH_VALUES)}, got {auth!r}")
    if not isinstance(server, bool):
        raise SpecError(f"{key}: x-didit-sdk.server must be true or false")
    if scope not in (None, "application"):
        raise SpecError(f"{key}: x-didit-sdk.scope must be \"application\" when present, got {scope!r}")
    return Naming(resource, method, auth, server, scope)


@dataclass
class Spec:
    root: Path
    verification: Document
    account: Document
    overlay: dict[str, Any]
    deprecated: list[dict[str, Any]]
    operations: list[Operation]

    @property
    def server_operations(self) -> list[Operation]:
        return [o for o in self.operations if o.naming.server]


def load(root: Path) -> Spec:
    openapi = root / "openapi"
    verification = Document("verification", json.loads((openapi / "verification.json").read_text("utf-8")))
    account = Document("account", json.loads((openapi / "account.json").read_text("utf-8")))
    overlay = json.loads((openapi / "overlay.json").read_text("utf-8")).get("operations", {})
    deprecated_path = openapi / "deprecated-operations.json"
    deprecated = []
    if deprecated_path.exists():
        deprecated = json.loads(deprecated_path.read_text("utf-8")).get("operations", [])

    operations: list[Operation] = []
    missing: list[str] = []
    for doc, prefix in ((verification, ""), (account, "account ")):
        for path, item in doc.data.get("paths", {}).items():
            item = doc.deref(item)
            for method in HTTP_METHODS:
                if method not in item:
                    continue
                op = item[method]
                key = f"{prefix}{method.upper()} {path}"
                raw = op.get("x-didit-sdk", overlay.get(key))
                if raw is None:
                    missing.append(key)
                    continue
                operations.append(Operation(key, doc, method.upper(), path, op, item, _naming_from(raw, key)))
    if missing:
        lines = "\n".join(f"  - {k}" for k in missing)
        raise SpecError(
            "These operations have no SDK naming (x-didit-sdk). Add an entry for each to "
            f"openapi/overlay.json (or x-didit-sdk upstream), see docs/DESIGN.md section 4:\n{lines}"
        )

    present = {o.key: o for o in operations}
    for entry in deprecated:
        key = entry["key"]
        if entry.get("alias"):
            alias = _alias(entry, present.get(key))
            if alias is not None:
                operations.append(alias)
            continue
        if key in present:
            continue  # the operation came back upstream
        base = account if key.startswith("account ") else verification
        # The entry carries the component schemas its operation used, so it
        # builds without the upstream document it was removed from, and its
        # types keep their component names.
        doc = Document(base.name, {"components": entry.get("components", {})})
        method, path = key.removeprefix("account ").split(" ", 1)
        op = entry["operation"]
        operations.append(
            Operation(
                key, doc, method, path, op, {"parameters": []}, _naming_from(entry["x-didit-sdk"], key),
                deprecated=True, removed_note=entry.get("removed_on"),
            )
        )

    _validate(operations)
    for o in operations:
        o.parameters = _parameters(o)
    return Spec(root, verification, account, overlay, deprecated, operations)


def _alias(entry: dict[str, Any], current: Operation | None) -> Operation | None:
    """The previous SDK name of a renamed operation, kept as a deprecated method."""
    if current is None or not current.naming.server:
        return None
    old = _naming_from(entry["x-didit-sdk"], current.key)
    n = current.naming
    if (old.resource, old.method) == (n.resource, n.method):
        return None  # the rename was reverted
    return Operation(
        current.key, current.doc, current.http_method, current.path, current.op, current.path_item,
        Naming(old.resource, old.method, n.auth, n.server, n.scope),
        deprecated=True, removed_note=entry.get("removed_on"), renamed_to=n,
    )


def _parameters(o: Operation) -> list[dict[str, Any]]:
    merged: dict[tuple[str, str], dict[str, Any]] = {}
    for raw in list(o.path_item.get("parameters", [])) + list(o.op.get("parameters", [])):
        p = o.doc.deref(raw)
        merged[(p["in"], p["name"])] = p
    return list(merged.values())


def _validate(operations: list[Operation]) -> None:
    errors: list[str] = []
    seen: dict[tuple[str, str], str] = {}
    for o in operations:
        n = o.naming
        if not n.server:
            continue
        if n.auth in ("device_token", "public"):
            errors.append(f"{o.key}: server operations cannot use auth {n.auth!r}; set server to false")
        ident = (n.resource, n.method)
        if ident in seen:
            errors.append(f"{o.key}: {n.resource}.{n.method} is already used by {seen[ident]}")
        seen[ident] = o.key
        if n.scope == "application" and not o.path.startswith(APPLICATION_PREFIX):
            errors.append(f"{o.key}: scope application needs a path starting with {APPLICATION_PREFIX}")
        if n.scope != "application" and o.path.startswith(APPLICATION_PREFIX) and n.server:
            errors.append(f"{o.key}: an application-scoped path needs scope \"application\"")
    resources = {o.naming.resource for o in operations if o.naming.server}
    for o in operations:
        if not o.naming.server:
            continue
        child = f"{o.naming.resource}.{o.naming.method}"
        if child in resources:
            errors.append(f"{o.key}: method {o.naming.method} clashes with the sub-resource {child}")
    if errors:
        raise SpecError("Invalid SDK naming:\n" + "\n".join(f"  - {e}" for e in errors))
