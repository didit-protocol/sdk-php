"""Compares two versions of an upstream document, operation by operation
(docs/DESIGN.md section 3.1): added, removed and changed operations, changed
component schemas, and the changes that can break typed code, by the rules
that openapi/breaking-vectors.json checks in all seven server SDKs.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

from . import model, naming
from .spec import HTTP_METHODS, Document

SCHEMA_REF = "#/components/schemas/"

# Keys that only document; a change limited to them is "documentation only".
# Extension keys (`x-...`) document too: the generator reads none of them
# inside an operation's parameters, request body or responses.
DOC_KEYS = frozenset({"description", "summary", "title", "example", "examples", "externalDocs", "x-codeSamples"})
# Keys whose value maps names (properties, media types, statuses) to objects.
NAME_MAPS = frozenset({"properties", "content", "responses", "headers", "schemas", "parameters", "patternProperties"})
# Lists whose order means nothing.
UNORDERED = frozenset({"required", "type"})
UNIONS = ("oneOf", "anyOf")
# String formats with their own type in typed code: gaining, losing or changing one changes the type.
TYPED_FORMATS = frozenset({"date", "date-time", "binary"})
PARAMETER_LOCATIONS = frozenset({"path", "query", "header", "cookie"})
# Kinds of the operation itself: their line has no `<where> <field>`.
OPERATION_KINDS = frozenset({"body now required", "operation removed"})
MAX_DEPTH = 32


def _doc_key(key: str) -> bool:
    return key in DOC_KEYS or key.startswith("x-")


def _list(value: Any) -> list[Any]:
    return value if isinstance(value, list) else []


def _dict(value: Any) -> dict[str, Any]:
    return value if isinstance(value, dict) else {}


def _target(where: str, path: str) -> str:
    """Where a change is, as the summary and the release notes say it."""
    if where in PARAMETER_LOCATIONS:
        return f"{where} parameter `{path}`"
    return f"{where} `{path}`" if path else where


@dataclass(frozen=True)
class Break:
    """A change that can break typed code (docs/DESIGN.md section 3.1).

    ``where`` is ``response`` (the body of any 2xx status), ``request body`` or
    a parameter's location (``query``, ``path``, ``header``, ``cookie``).
    ``field`` is the path below it: ``a.b[].c``, ``counts{}`` for a map's
    values, ``(oneOf 2).iban`` in the second variant of a union after the
    change (a ``null`` variant counted); a parameter's starts with its name;
    empty for a whole body. ``kind`` is spelled like openapi/breaking-vectors.json:
    ``removed``, ``type <old> -> <new>``, ``can now be null``, ``no longer
    required``, ``now required``, ``enum value <JSON value> removed``, or
    ``body now required``, which belongs to the operation.
    """

    where: str
    field: str
    kind: str

    def line(self, operation: str) -> str:
        """The normalized line the shared vectors compare: ``<METHOD> <path> <where> <field>: <kind>``."""
        if self.kind in OPERATION_KINDS:
            return f"{operation}: {self.kind}"
        return f"{operation} {' '.join(p for p in (self.where, self.field) if p)}: {self.kind}"

    def __str__(self) -> str:
        # Never "<" or ">": release-please escapes them in the release notes.
        kind = self.kind
        if kind == "body now required":
            return "request body: now required"
        if kind.startswith("type "):
            old, new = (t.replace("|", " or ") for t in kind[len("type "):].split(" -> ", 1))
            kind = f"type changed from {old} to {new}"
        elif kind.startswith("enum value "):
            value = json.loads(kind[len("enum value "):-len(" removed")])
            kind = f"enum value `{value if isinstance(value, str) else json.dumps(value)}` removed"
        return f"{_target(self.where, self.field)}: {kind}"


@dataclass
class OpChange:
    key: str
    parts: list[str]  # "parameters", "request body", "response 200"
    docs_only: bool
    breaking: list[Break] = field(default_factory=list)
    notes: list[str] = field(default_factory=list)


@dataclass
class SchemaChanges:
    added: list[str] = field(default_factory=list)
    removed: list[str] = field(default_factory=list)
    changed: list[tuple[str, bool]] = field(default_factory=list)  # (name, documentation only)


def operations(doc: Document, prefix: str) -> dict[str, tuple[dict[str, Any], dict[str, Any]]]:
    """``{"POST /v3/session/": (operation, path item)}``, keyed like ``spec.load``."""
    out: dict[str, tuple[dict[str, Any], dict[str, Any]]] = {}
    for path, item in doc.data.get("paths", {}).items():
        item = doc.deref(item)
        if not isinstance(item, dict):
            continue
        for method in HTTP_METHODS:
            if isinstance(item.get(method), dict):
                out[f"{prefix}{method.upper()} {path}"] = (item[method], item)
    return out


def merged_parameters(doc: Document, op: dict[str, Any], item: dict[str, Any]) -> dict[str, dict[str, Any]]:
    """Path-item and operation parameters by ``"<in> <name>"``; the operation wins."""
    out: dict[str, dict[str, Any]] = {}
    for raw in list(item.get("parameters", [])) + list(op.get("parameters", [])):
        p = doc.deref(raw)
        if isinstance(p, dict) and "in" in p and "name" in p:
            out[f"{p['in']} {p['name']}"] = p
    return out


def _parts(doc: Document, op: dict[str, Any], item: dict[str, Any]) -> dict[str, Any]:
    parts: dict[str, Any] = {"parameters": merged_parameters(doc, op, item), "request body": op.get("requestBody")}
    for status, response in sorted(op.get("responses", {}).items()):
        if str(status).startswith("2"):
            parts[f"response {status}"] = response
    return parts


def _variant_title(node: dict[str, Any]) -> str | None:
    """The name an inline ``oneOf``/``anyOf`` variant's title gives its type, else None."""
    title = node.get("title")
    if "$ref" in node or not isinstance(title, str) or not title.strip():
        return None
    return naming.pascal(title)


class Comparer:
    """Deep equality of two spec fragments after resolving ``$ref`` values.

    ``ignore_docs`` skips documentation, except the title of an inline union
    variant, which renames the variant (listed, DESIGN 3.1). With ``type_names``
    a field that now uses a differently named component differs even when the
    content is the same: the generator names the type after it.
    """

    def __init__(self, old: Document, new: Document, ignore_docs: bool = False, type_names: bool = True) -> None:
        self.old = old
        self.new = new
        self.ignore = ignore_docs
        self.type_names = type_names
        self._memo: dict[tuple[Any, Any], bool] = {}
        self._active: set[tuple[Any, Any]] = set()
        self._named: dict[tuple[bool, str], bool] = {}

    def type_name(self, doc: Document, ref: Any) -> str | None:
        """The component ``ref`` points to when the generator names a public type
        after it (an object or enum, also as array items or map values), else None."""
        if not isinstance(ref, str) or not ref.startswith(SCHEMA_REF):
            return None
        key = (doc is self.new, ref)
        if key not in self._named:
            self._named[key] = names_type(doc, ref)
        return ref[len(SCHEMA_REF):] if self._named[key] else None

    def renamed(self, ref_a: Any, ref_b: Any) -> bool:
        """Whether the generated type changes name: a field now uses a differently
        named component, or an inline schema became a named component or the other
        way round. Its content can be identical, but typed code names the type."""
        if ref_a == ref_b:
            return False
        a, b = self.type_name(self.old, ref_a), self.type_name(self.new, ref_b)
        if a is None or b is None:
            return a != b
        return naming.class_name(a) != naming.class_name(b)

    def equal(self, a: Any, b: Any, names: bool = False, variant: bool = False) -> bool:
        if isinstance(a, dict) and isinstance(b, dict):
            if not names and ("$ref" in a or "$ref" in b):
                return self._refs(a, b)
            keys = set(a) | set(b)
            if not names and self.ignore:
                keys = {k for k in keys if not _doc_key(k)}
                if variant and _variant_title(a) != _variant_title(b):
                    return False
            for k in keys:
                if k not in a or k not in b:
                    return False
                x, y = a[k], b[k]
                if not names and isinstance(x, list) and isinstance(y, list):
                    if k in UNORDERED:
                        x, y = sorted(x, key=str), sorted(y, key=str)
                    elif k in UNIONS:
                        if len(x) != len(y) or not all(self.equal(v, w, variant=True) for v, w in zip(x, y)):
                            return False
                        continue
                if not self.equal(x, y, not names and k in NAME_MAPS):
                    return False
            return True
        if isinstance(a, list) and isinstance(b, list):
            return len(a) == len(b) and all(self.equal(x, y) for x, y in zip(a, b))
        return type(a) is type(b) and a == b

    def _refs(self, a: dict[str, Any], b: dict[str, Any]) -> bool:
        key = (a.get("$ref"), b.get("$ref"))
        if self.type_names and self.renamed(*key):
            return False
        pure = set(a) <= {"$ref"} and set(b) <= {"$ref"}
        if pure and key in self._memo:
            return self._memo[key]
        if key in self._active:
            return True  # a recursive schema: equal unless something else differs
        self._active.add(key)
        try:
            result = self.equal(_expand(self.old, a), _expand(self.new, b))
        finally:
            self._active.discard(key)
        if pure:
            self._memo[key] = result
        return result


def _expand(doc: Document, node: dict[str, Any]) -> Any:
    """The target of a ``$ref`` with the sibling keys applied on top."""
    if "$ref" not in node:
        return node
    target = doc.deref(node)
    siblings = {k: v for k, v in node.items() if k != "$ref"}
    if isinstance(target, dict) and siblings:
        return {**target, **siblings}
    return target


def names_type(doc: Document, ref: str) -> bool:
    """Whether the generator emits a type named after the component ``ref``,
    asked of the generator itself: a string alias or an array of another
    component names nothing, so renaming it changes no code."""
    origin = f"{doc.name}:{ref}"
    reg = model.Registry()
    reg.convert(doc, {"$ref": ref}, "", "response", origin)
    own = (origin + ".", origin + "[]", origin + "{}")
    return any(t.origin == origin or t.origin.startswith(own) for t in (*reg.models.values(), *reg.enums.values()))


def strip_docs(node: Any, names: bool = False) -> Any:
    """``node`` without documentation keys, for shallow comparisons."""
    if isinstance(node, dict):
        return {
            k: strip_docs(v, not names and k in NAME_MAPS)
            for k, v in node.items()
            if names or not _doc_key(k)
        }
    if isinstance(node, list):
        return [strip_docs(v) for v in node]
    return node


# Schemas, read the way docs/DESIGN.md section 3.1 compares them -------------------
#
# Each helper takes the document the schema belongs to and follows its `$ref`
# values lazily, so that a recursive schema ends.


def _resolve(doc: Document, s: Any) -> dict[str, Any]:
    """``s`` with its ``$ref`` followed; a schema that is not an object (``true``) allows any value."""
    if isinstance(s, dict):
        s = _expand(doc, s)
    return s if isinstance(s, dict) else {}


def _is_null(doc: Document, s: Any) -> bool:
    """``{"type": "null"}``, the null variant of a ``oneOf``/``anyOf`` (OpenAPI 3.1)."""
    s = _resolve(doc, s)
    return s.get("type") == "null" or s.get("enum") == [None]


def _variants(doc: Document, s: dict[str, Any], keyword: str | None = None) -> list[Any]:
    """The ``oneOf``/``anyOf`` variants of ``s`` other than ``null`` (of ``keyword`` only, when given)."""
    return [v for k in ((keyword,) if keyword else UNIONS) for v in _list(s.get(k)) if not _is_null(doc, v)]


def _positions(doc: Document, s: dict[str, Any]) -> list[str]:
    """Each of ``_variants(s)`` as a field names it: its keyword and its position from 1,
    a ``null`` variant counted (``anyOf 2`` in ``[null, Card, Bank]``)."""
    return [f"{k} {n}" for k in UNIONS for n, v in enumerate(_list(s.get(k)), 1) if not _is_null(doc, v)]


def _union_keyword(doc: Document, s: dict[str, Any]) -> str | None:
    return next((k for k in UNIONS if _variants(doc, s, k)), None)


def _with_required(doc: Document, variant: Any, union: dict[str, Any]) -> Any:
    """A union's variant with the fields the union itself requires, which apply to
    every variant: dropping one from the union drops it from each variant."""
    inherited = {n for n in _list(union.get("required")) if isinstance(n, str)}
    if not inherited:
        return variant
    v = _resolve(doc, variant)
    own = {n for n in _list(v.get("required")) if isinstance(n, str)}
    return {**v, "required": sorted(own | inherited)}


def nullable(doc: Document, s: Any, depth: int = 0) -> bool:
    """Whether ``s`` accepts null: a ``null`` type, ``nullable: true`` (OpenAPI 3.0),
    null in its enum, ``allOf`` parts that all accept null, or a null or nullable
    ``oneOf``/``anyOf`` variant (3.1), here or through ``$ref``."""
    s = _resolve(doc, s)
    if depth > MAX_DEPTH:
        return False
    typ = s.get("type")
    if typ == "null" or (isinstance(typ, list) and "null" in typ) or s.get("nullable") is True:
        return True
    if None in _list(s.get("enum")):
        return True
    parts = _list(s.get("allOf"))
    if "type" not in s and parts and all(nullable(doc, p, depth + 1) for p in parts):
        return True
    return any(nullable(doc, v, depth + 1) for k in UNIONS for v in _list(s.get(k)))


def _single(doc: Document, s: Any, depth: int = 0) -> dict[str, Any]:
    """``s`` read as one type: its ``allOf`` parts merged in, and a ``oneOf``/``anyOf``
    of one variant besides ``null`` replaced by that variant, so that
    ``anyOf: [X, null]`` reads as a nullable X, not as X's fields removed."""
    s = _resolve(doc, s)
    if depth > MAX_DEPTH:
        return s
    parts = s.get("allOf")
    if isinstance(parts, list) and all(isinstance(p, dict) for p in parts):
        merged = {k: v for k, v in s.items() if k != "allOf"}
        for part in parts:
            for key, value in _single(doc, part, depth + 1).items():
                if key == "properties" and isinstance(value, dict):
                    merged["properties"] = {**value, **_dict(merged.get("properties"))}
                elif key == "required" and isinstance(value, list):
                    merged["required"] = sorted({*_list(merged.get("required")), *value})
                else:
                    merged.setdefault(key, value)
        s = merged
    variants = _variants(doc, s)
    if len(variants) != 1:
        return s
    rest = {k: v for k, v in s.items() if k not in ("oneOf", "anyOf", "required")}
    return _single(doc, {**_resolve(doc, _with_required(doc, variants[0], s)), **rest}, depth + 1)


def _json_type(value: Any) -> str:
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int):
        return "integer"
    if isinstance(value, float):
        return "number"
    if isinstance(value, str):
        return "string"
    return "array" if isinstance(value, list) else "object"


def _kinds(doc: Document, s: Any, depth: int = 0) -> frozenset[str]:
    """The JSON types ``s`` allows besides null, inferred when it has no ``type`` (a
    union allows its variants' types); empty when it does not say: any value."""
    s = _resolve(doc, s)
    if depth > MAX_DEPTH:
        return frozenset()
    typ = s.get("type")
    kinds = {t for t in typ if isinstance(t, str)} if isinstance(typ, list) else ({typ} if isinstance(typ, str) else set())
    kinds.discard("null")
    if kinds:
        return frozenset(kinds)
    if any(k in s for k in ("properties", "additionalProperties", "patternProperties")):
        return frozenset({"object"})
    if "items" in s:
        return frozenset({"array"})
    variants = _variants(doc, s)
    if variants:
        found = [_kinds(doc, v, depth + 1) for v in variants]
        return frozenset().union(*found) if all(found) else frozenset()
    for part in _list(s.get("allOf")):
        kinds |= _kinds(doc, part, depth + 1)
    if not kinds:
        values = [s["const"]] if "const" in s else _list(s.get("enum"))
        kinds = {_json_type(v) for v in values if v is not None}
    return frozenset(kinds)


def _type_name(s: dict[str, Any], kinds: frozenset[str]) -> str:
    """``kinds`` as the shared vectors name them, joined with ``|``: a string with a
    typed format is ``string (date)``, and a schema that does not say is ``any``."""
    if not kinds:
        return "any"
    fmt = s.get("format")
    typed = isinstance(fmt, str) and fmt in TYPED_FORMATS
    return "|".join(f"string ({fmt})" if k == "string" and typed else k for k in sorted(kinds))


def _union_names(doc: Document, schemas: list[Any]) -> str:
    """One type per schema (a union's variants, a method's 2xx bodies), sorted, so
    that losing one of two object variants reads ``object|object -> object``."""
    names: list[str] = []
    for s in schemas:
        single = _single(doc, s)
        names.extend(_type_name(single, _kinds(doc, single)).split("|"))
    return "|".join(sorted(names))


def _enum(s: dict[str, Any]) -> list[Any] | None:
    values = s.get("enum")
    return [v for v in values if v is not None] if isinstance(values, list) else None


def _fields(s: dict[str, Any]) -> tuple[dict[str, Any], set[str]]:
    """The properties and required names of ``s``, already read as one type (``_single``)."""
    return _dict(s.get("properties")), {n for n in _list(s.get("required")) if isinstance(n, str)}


def _join(path: str, name: str) -> str:
    return f"{path}.{name}" if path else name


def _ref(doc: Document, s: Any) -> Any:
    """The ``$ref`` the generator names the type of ``s`` after, also through a
    one-part ``allOf`` or a ``oneOf``/``anyOf`` of one variant and ``null``."""
    if not isinstance(s, dict):
        return None
    if "$ref" in s:
        return s["$ref"]
    all_of = s.get("allOf")
    if isinstance(all_of, list) and len(all_of) == 1:
        return _ref(doc, all_of[0])
    variants = _variants(doc, s)
    if len(variants) == 1:
        return _ref(doc, variants[0])
    return None


def _content(doc: Document, node: Any) -> dict[str, Any]:
    """The schema of each media type of a request body or response; a media type
    without one allows any value."""
    content = _resolve(doc, node).get("content")
    if not isinstance(content, dict):
        return {}
    return {media: entry.get("schema") or {} for media, entry in content.items() if isinstance(entry, dict)}


def _successes(doc: Document, op: dict[str, Any]) -> dict[str, dict[str, Any]]:
    """The content of each 2xx response, by status."""
    responses = _dict(op.get("responses"))
    return {str(s): _content(doc, responses[s]) for s in sorted(responses, key=str) if str(s).startswith("2")}


class Details:
    """Collects the changes that can break typed code (counted) and the others
    (notes) between two operations, by the rules of docs/DESIGN.md section 3.1."""

    def __init__(self, old: Document, new: Document, same: Comparer | None = None) -> None:
        self.old = old
        self.new = new
        # Prunes unchanged subtrees; shared across operations for its memo.
        self.same = same if same is not None else Comparer(old, new, ignore_docs=True)
        # What is sent or received, whatever the components are called: pairs union variants and statuses.
        self.shape = Comparer(old, new, ignore_docs=True, type_names=False)
        self._sides: dict[bool, Comparer] = {}
        self.breaking: list[Break] = []
        self.notes: list[str] = []
        self._active: set[tuple[Any, ...]] = set()

    def _count(self, where: str, path: str, kind: str) -> None:
        self.breaking.append(Break(where, path, kind))

    def _note(self, where: str, path: str, text: str) -> None:
        self.notes.append(f"{_target(where, path)}: {text}")

    def schema(
        self, a: Any, b: Any, where: str, path: str, request: bool, present: bool = True, named: bool = True,
    ) -> None:
        """A schema shared by several fields is compared at each of them; only
        recursion stops the walk. ``present`` is False when a request body or
        response appears or disappears: its fields are compared, not its type.
        ``named`` is False for a union's variants, whose component names the
        generator merges into one type."""
        ra = a.get("$ref") if isinstance(a, dict) else None
        rb = b.get("$ref") if isinstance(b, dict) else None
        key = (ra, rb, request)
        if (ra or rb) and key in self._active:
            return
        if self.same.equal(a, b):
            return
        if ra or rb:
            self._active.add(key)
        try:
            self._schema(a, b, where, path, request, present, named)
        finally:
            if ra or rb:
                self._active.discard(key)

    def _schema(self, raw_a: Any, raw_b: Any, where: str, path: str, request: bool, present: bool, named: bool) -> None:
        old, new = self.old, self.new
        a, b = _resolve(old, raw_a), _resolve(new, raw_b)
        if nullable(new, b) and not nullable(old, a):
            # A request that accepts null only widens what a caller may send: listed.
            if request:
                self._note(where, path, "can now be null")
            else:
                self._count(where, path, "can now be null")
        elif nullable(old, a) and not nullable(new, b):
            self._note(where, path, "no longer accepts null" if request else "can no longer be null")
        if named:
            self._renamed_type(raw_a, raw_b, where, path)
        a, b = _single(old, a), _single(new, b)
        ka, kb = _kinds(old, a), _kinds(new, b)
        ta, tb = _type_name(a, ka), _type_name(b, kb)
        # No type at all is a type too: the generator reads it as any value.
        if present and (ka or kb) and ta != tb:
            self._count(where, path, f"type {ta} -> {tb}")
            # The variants still pair, also when an untyped variant made the union `any`;
            # the type change already says that some went away.
            if (ka or _variants(old, a)) and (kb or _variants(new, b)):
                self._union(a, b, where, path, request, retyped=True)
            return
        self._enum_values(a, b, where, path)
        pa, req_a = _fields(a)
        pb, req_b = _fields(b)
        # A union's fields are those of its variants, compared below.
        if (pa and pb) or not (_variants(old, a) or _variants(new, b)):
            self._properties(pa, req_a, pb, req_b, where, path, request)
        for key, suffix in (("items", "[]"), ("additionalProperties", "{}")):
            sa, sb = a.get(key), b.get(key)
            if isinstance(sa, dict) or isinstance(sb, dict):
                self.schema(_dict(sa), _dict(sb), where, path + suffix, request)
        self._union(a, b, where, path, request, retyped=False)

    def _renamed_type(self, raw_a: Any, raw_b: Any, where: str, path: str) -> None:
        """A field that now uses a differently named component renames its generated
        type: listed, not counted (DESIGN 3.1 does not list it)."""
        ref_a, ref_b = _ref(self.old, raw_a), _ref(self.new, raw_b)
        if not self.same.renamed(ref_a, ref_b):
            return
        a, b = self.same.type_name(self.old, ref_a), self.same.type_name(self.new, ref_b)
        if a and b:
            self._note(where, path, f"type renamed from `{a}` to `{b}`")
        elif b:
            self._note(where, path, f"now the type `{b}`")
        elif a:
            self._note(where, path, f"no longer the type `{a}`")

    def _enum_values(self, a: dict[str, Any], b: dict[str, Any], where: str, path: str) -> None:
        va, vb = _enum(a), _enum(b)
        if va is not None and vb is not None:
            for v in va:
                if v not in vb:
                    self._count(where, path, f"enum value {json.dumps(v, ensure_ascii=False)} removed")
            added = [f"`{v}`" for v in vb if v not in va]
            if added:
                self._note(where, path, f"enum value {', '.join(added)} added")
        elif vb is not None:
            self._note(where, path, "now limited to fixed values")
        elif va is not None:
            self._note(where, path, "no longer limited to fixed values")

    def _properties(
        self, pa: dict[str, Any], req_a: set[str], pb: dict[str, Any], req_b: set[str], where: str, path: str, request: bool,
    ) -> None:
        for name in sorted(set(pa) - set(pb)):
            self._count(where, _join(path, name), "removed")
        for name in sorted(set(pb) - set(pa)):
            if request and name in req_b:
                self._count(where, _join(path, name), "now required")
            else:
                self._note(where, _join(path, name), "field added")
        for name in sorted(set(pa) & set(pb)):
            child = _join(path, name)
            if name in req_b and name not in req_a:
                if request:
                    self._count(where, child, "now required")
                else:
                    self._note(where, child, "now always present")
            elif name in req_a and name not in req_b:
                # A response field that may be missing breaks code that reads it;
                # a request field that may be left out only widens what a caller sends.
                if request:
                    self._note(where, child, "no longer required")
                else:
                    self._count(where, child, "no longer required")
            self.schema(pa[name], pb[name], where, child, request)

    def _union(self, a: dict[str, Any], b: dict[str, Any], where: str, path: str, request: bool, retyped: bool) -> None:
        """Compares two unions variant by variant; a plain schema is a union of one,
        so that ``[X, null]`` against X loses no variant and X against ``[X', Y]``
        compares X with X'. A variant added or removed never hides a change inside
        the others."""
        old_vars, new_vars = _variants(self.old, a) or [a], _variants(self.new, b) or [b]
        if len(old_vars) == 1 and len(new_vars) == 1:
            return  # compared as the schema itself
        keyword = _union_keyword(self.new, b) or _union_keyword(self.old, a)
        pairs, removed, added = self._pair(old_vars, new_vars)
        positions = _positions(self.new, b)
        for i, j in pairs:
            at = f"{path}({positions[j]})" if len(new_vars) > 1 else path
            x, y = _with_required(self.old, old_vars[i], a), _with_required(self.new, new_vars[j], b)
            self.schema(x, y, where, at, request, named=False)
            before, after = (_variant_title(_dict(v)) for v in (old_vars[i], new_vars[j]))
            if before != after:
                self._note(where, at, f"variant renamed from {before or 'a numbered name'} to {after or 'a numbered name'}")
        if removed and not retyped:
            self._count(where, path, f"type {_union_names(self.old, old_vars)} -> {_union_names(self.new, new_vars)}")
        if added:
            self._note(where, path, f"{len(added)} {keyword} variant(s) added")
        order = [j for _, j in sorted(pairs)]
        if order != sorted(order):
            self._note(where, path, f"{keyword} variants reordered")

    def _pair(self, old: list[Any], new: list[Any]) -> tuple[list[tuple[int, int]], list[int], list[int]]:
        """Pairs each old variant with the new one it became: an unchanged one first,
        then one with the same title, then one of a compatible type with the most
        fields in common. Returns the pairs (old and new position, in the new
        order) and the positions left over: variants removed and added."""
        left_old, left_new = list(range(len(old))), list(range(len(new)))
        pairs: list[tuple[int, int]] = []

        def same_shape(i: int, j: int) -> bool:
            return self.shape.equal(old[i], new[j])

        def same_title(i: int, j: int) -> bool:
            title = _dict(old[i]).get("title")
            return title is not None and title == _dict(new[j]).get("title")

        for same in (same_shape, same_title):
            for i in list(left_old):
                j = next((j for j in left_new if same(i, j)), None)
                if j is not None:
                    pairs.append((i, j))
                    left_old.remove(i)
                    left_new.remove(j)
        kinds_old = [_kinds(self.old, v) for v in old]
        kinds_new = [_kinds(self.new, v) for v in new]
        fields_old = [set(_fields(_single(self.old, v))[0]) for v in old]
        fields_new = [set(_fields(_single(self.new, v))[0]) for v in new]
        candidates = sorted(
            (-len(fields_old[i] & fields_new[j]), i, j)
            for i in left_old
            for j in left_new
            if not kinds_old[i] or not kinds_new[j] or kinds_old[i] == kinds_new[j]
        )
        for _, i, j in candidates:
            if i in left_old and j in left_new:
                pairs.append((i, j))
                left_old.remove(i)
                left_new.remove(j)
        return sorted(pairs, key=lambda p: p[1]), left_old, left_new

    def parameters(self, pa: dict[str, dict[str, Any]], pb: dict[str, dict[str, Any]]) -> None:
        """A parameter is its location (``where``) and its name, which starts its field."""
        for key in sorted(set(pa) - set(pb)):
            self._count(pa[key]["in"], pa[key]["name"], "removed")
        for key in sorted(set(pb) - set(pa)):
            q = pb[key]
            if q.get("required") is True:
                self._count(q["in"], q["name"], "now required")
            else:
                self._note(q["in"], q["name"], "added")
        for key in sorted(set(pa) & set(pb)):
            p, q = pa[key], pb[key]
            if q.get("required") is True and p.get("required") is not True:
                self._count(q["in"], q["name"], "now required")
            elif p.get("required") is True and q.get("required") is not True:
                self._note(q["in"], q["name"], "no longer required")
            self.schema(p.get("schema") or {}, q.get("schema") or {}, q["in"], q["name"], True)

    def request_body(self, ra: Any, rb: Any) -> None:
        was = _resolve(self.old, ra).get("required") is True
        now = _resolve(self.new, rb).get("required") is True
        if now and not was:
            self._count("request body", "", "body now required")
        elif was and not now:
            self._note("request body", "", "no longer required")
        self._bodies(_content(self.old, ra), _content(self.new, rb), "request body", True)

    def _bodies(self, old: dict[str, Any], new: dict[str, Any], where: str, request: bool) -> None:
        """A body per media type; when none is shared, the first against the first,
        so that a body that changes media type, appears or disappears is compared too."""
        if old and new and set(old) != set(new):
            self._note(where, "", f"content type changed from {', '.join(sorted(old))} to {', '.join(sorted(new))}")
        shared = sorted(set(old) & set(new))
        for media in shared:
            self.schema(old[media], new[media], where, "", request)
        if not shared and (old or new):
            a = old[min(old)] if old else {}
            b = new[min(new)] if new else {}
            self.schema(a, b, where, "", request, present=bool(old) and bool(new))

    def responses(self, oa: dict[str, Any], ob: dict[str, Any]) -> None:
        """The method returns the body of every 2xx status. A status both versions
        have, with a body in both or in neither, compares its bodies; so do the only
        success statuses of each version when they differ (200 to 201). The others
        change what the method returns (``_statuses``)."""
        sa, sb = _successes(self.old, oa), _successes(self.new, ob)
        pairs = [(s, s) for s in sa if s in sb and bool(sa[s]) == bool(sb[s])]
        if not pairs and len(sa) == 1 == len(sb) and bool(next(iter(sa.values()))) == bool(next(iter(sb.values()))):
            pairs = [(next(iter(sa)), next(iter(sb)))]
            self._note("response", "", f"success status changed from {pairs[0][0]} to {pairs[0][1]}")
        else:
            compared = {s for s, _ in pairs}
            self._statuses(sa, sb, [s for s in sa if s not in compared], [s for s in sb if s not in compared])
        for s1, s2 in pairs:
            self._bodies(sa[s1], sb[s2], "response", False)

    def _statuses(self, old: dict[str, dict[str, Any]], new: dict[str, dict[str, Any]], removed: list[str], added: list[str]) -> None:
        """The statuses only one version has, or whose body appeared or disappeared:
        a status without a body makes the method's result nullable, and a removed
        status whose body no other status returns removes one of the types it
        returns (``null`` when no body is left)."""
        old_bodies = [c for c in old.values() if c]
        new_bodies = [c for c in new.values() if c]
        old_null = any(not c for c in old.values())
        new_null = any(not c for c in new.values())
        for status in added:
            if not new[status] and old_bodies and not old_null:
                self._count("response", "", "can now be null")
                self._note("response", "", f"status {status} {'lost its body' if status in old else 'has no body'}")
            elif status in old:
                continue  # a body that appeared or disappeared: said with the old side below
            elif new[status] and not any(self.shape.equal(c, new[status], True) for c in old_bodies):
                self._note("response", "", f"status {status} added with another body")
            else:
                self._note("response", "", f"status {status} added")
        for status in removed:
            change = "removed"
            if status in new:
                change = "lost its body" if old[status] else "now has a body"
            if not old[status] and new_bodies and not new_null:
                self._note("response", "", f"can no longer be null (status {status} {change})")
            elif old[status] and not any(self.shape.equal(old[status], c, True) for c in new_bodies):
                self._count("response", "", f"type {self._returns(self.old, old)} -> {self._returns(self.new, new)}")
                self._note("response", "", f"status {status} {change}, and no other status returns its body")
            else:
                self._note("response", "", f"status {status} {change}")

    def _returns(self, doc: Document, responses: dict[str, dict[str, Any]]) -> str:
        """The types the 2xx bodies of one version return, one per distinct body; ``null`` for none."""
        side = doc is self.new
        if side not in self._sides:
            self._sides[side] = Comparer(doc, doc, ignore_docs=True, type_names=False)
        same = self._sides[side]
        distinct: list[dict[str, Any]] = []
        for content in responses.values():
            if content and not any(same.equal(d, content, True) for d in distinct):
                distinct.append(content)
        bodies = [c.get("application/json", c[min(c)]) for c in distinct]
        return _union_names(doc, bodies) or "null"


# Documents ----------------------------------------------------------------------


def compare_operation(
    old: Document,
    new: Document,
    key: str,
    a: tuple[dict[str, Any], dict[str, Any]],
    b: tuple[dict[str, Any], dict[str, Any]],
    exact: Comparer | None = None,
    structural: Comparer | None = None,
) -> OpChange | None:
    """How operation ``a`` of ``old`` became operation ``b`` of ``new`` (the same
    operation, or the one a moved method now calls), or None when they are equal."""
    exact = exact if exact is not None else Comparer(old, new)
    structural = structural if structural is not None else Comparer(old, new, ignore_docs=True)
    (oa, ia), (ob, ib) = a, b
    pa, pb = _parts(old, oa, ia), _parts(new, ob, ib)
    names = list(dict.fromkeys(list(pa) + list(pb)))
    differing = [n for n in names if not exact.equal(pa.get(n), pb.get(n), n == "parameters")]
    if not differing:
        return None
    docs_only = all(structural.equal(pa.get(n), pb.get(n), n == "parameters") for n in differing)
    change = OpChange(key, differing, docs_only)
    if not docs_only:
        d = Details(old, new, structural)
        d.parameters(pa["parameters"], pb["parameters"])
        d.request_body(oa.get("requestBody"), ob.get("requestBody"))
        d.responses(oa, ob)
        change.breaking = list(dict.fromkeys(d.breaking))
        change.notes = list(dict.fromkeys(d.notes))
    return change


def changed_operations(old: Document, new: Document, prefix: str) -> list[OpChange]:
    """Operations present in both versions whose parameters, request body or 2xx responses differ."""
    exact = Comparer(old, new)
    structural = Comparer(old, new, ignore_docs=True)
    old_ops, new_ops = operations(old, prefix), operations(new, prefix)
    out: list[OpChange] = []
    for key in sorted(set(old_ops) & set(new_ops)):
        change = compare_operation(old, new, key, old_ops[key], new_ops[key], exact, structural)
        if change is not None:
            out.append(change)
    return out


def schema_changes(old: Document, new: Document) -> SchemaChanges:
    a = old.data.get("components", {}).get("schemas", {})
    b = new.data.get("components", {}).get("schemas", {})
    out = SchemaChanges()
    out.added = sorted(n for n in b if n not in a)
    out.removed = sorted(n for n in a if n not in b)
    for name in sorted(set(a) & set(b)):
        if a[name] != b[name]:
            out.changed.append((name, strip_docs(a[name]) == strip_docs(b[name])))
    return out
