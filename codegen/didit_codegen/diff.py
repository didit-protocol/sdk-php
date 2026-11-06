"""Compares two versions of an upstream document, operation by operation
(docs/DESIGN.md section 3.1): added, removed and changed operations, changed
component schemas, and the changes that break SDK users.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from .spec import HTTP_METHODS, Document

SCHEMA_REF = "#/components/schemas/"

# Keys that only document; a change limited to them is "documentation only".
DOC_KEYS = frozenset({"description", "summary", "title", "example", "examples", "externalDocs", "x-codeSamples"})
# Keys whose value maps names (properties, media types, statuses) to objects.
NAME_MAPS = frozenset({"properties", "content", "responses", "headers", "schemas", "parameters", "patternProperties"})


@dataclass
class OpChange:
    key: str
    parts: list[str]  # "parameters", "request body", "response 200"
    docs_only: bool
    breaking: list[str] = field(default_factory=list)
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


class Comparer:
    """Deep equality of two spec fragments after resolving ``$ref`` values."""

    def __init__(self, old: Document, new: Document, ignore_docs: bool = False) -> None:
        self.old = old
        self.new = new
        self.ignore = DOC_KEYS if ignore_docs else frozenset()
        self._memo: dict[tuple[Any, Any], bool] = {}
        self._active: set[tuple[Any, Any]] = set()

    def equal(self, a: Any, b: Any, names: bool = False) -> bool:
        if isinstance(a, dict) and isinstance(b, dict):
            if not names and ("$ref" in a or "$ref" in b):
                return self._refs(a, b)
            keys = set(a) | set(b)
            if not names:
                keys -= self.ignore
            for k in keys:
                if k not in a or k not in b:
                    return False
                if not self.equal(a[k], b[k], not names and k in NAME_MAPS):
                    return False
            return True
        if isinstance(a, list) and isinstance(b, list):
            return len(a) == len(b) and all(self.equal(x, y) for x, y in zip(a, b))
        return type(a) is type(b) and a == b

    def _refs(self, a: dict[str, Any], b: dict[str, Any]) -> bool:
        key = (a.get("$ref"), b.get("$ref"))
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


def strip_docs(node: Any, names: bool = False) -> Any:
    """``node`` without documentation keys, for shallow comparisons."""
    if isinstance(node, dict):
        return {
            k: strip_docs(v, not names and k in NAME_MAPS)
            for k, v in node.items()
            if names or k not in DOC_KEYS
        }
    if isinstance(node, list):
        return [strip_docs(v) for v in node]
    return node


# Breaking-change details ----------------------------------------------------


def _kind(doc: Document, s: Any) -> str:
    """A coarse type name; an empty string means any type."""
    if not isinstance(s, dict):
        return ""
    variants = s.get("oneOf") or s.get("anyOf")
    if isinstance(variants, list) and variants:
        kinds = sorted({_kind(doc, flatten(doc, v)) or "any" for v in variants})
        return "|".join(kinds)
    typ = s.get("type")
    if isinstance(typ, list):
        typ = next((t for t in typ if t != "null"), None)
    if typ == "string":
        fmt = s.get("format")
        return f"string({fmt})" if fmt in ("date-time", "binary") else "string"
    if typ == "object" or s.get("properties"):
        if not s.get("properties") and isinstance(s.get("additionalProperties"), dict) and s["additionalProperties"]:
            return "map"
        return "object"
    if typ in ("integer", "number", "boolean", "array"):
        return str(typ)
    return ""


def _objectish(s: Any) -> bool:
    return isinstance(s, dict) and (bool(s.get("properties")) or s.get("type") == "object")


def flatten(doc: Document, s: Any) -> Any:
    """Resolves ``s`` and merges ``allOf`` parts, and ``oneOf``/``anyOf`` object variants."""
    s = doc.deref(s)
    if not isinstance(s, dict):
        return s
    all_of = s.get("allOf")
    if isinstance(all_of, list) and all_of:
        parts = [flatten(doc, p) for p in all_of]
        merged = {k: v for k, v in s.items() if k != "allOf"}
        if len(parts) == 1 and not _objectish(parts[0]):
            return {**parts[0], **merged}
        props: dict[str, Any] = {}
        required: list[str] = []
        for p in parts:
            if isinstance(p, dict):
                props.update(p.get("properties", {}))
                required.extend(p.get("required", []))
        merged.update({"type": "object", "properties": props, "required": required})
        return merged
    variants = s.get("oneOf") or s.get("anyOf")
    if isinstance(variants, list) and variants:
        flat = [flatten(doc, v) for v in variants]
        if all(_objectish(v) for v in flat):
            props = {}
            for v in flat:
                for name, prop in v.get("properties", {}).items():
                    props.setdefault(name, prop)
            required_sets = [set(v.get("required", [])) for v in flat]
            required = sorted(set.intersection(*required_sets)) if required_sets else []
            rest = {k: v for k, v in s.items() if k not in ("oneOf", "anyOf")}
            return {**rest, "type": "object", "properties": props, "required": required}
    return s


def _at(label: str, path: str) -> str:
    return f"{label} `{path}`" if path else label


class Details:
    """Collects breaking changes and notable additions between two operations."""

    def __init__(self, old: Document, new: Document) -> None:
        self.old = old
        self.new = new
        self.breaking: list[str] = []
        self.notes: list[str] = []
        self._seen: set[tuple[Any, ...]] = set()

    def schema(self, a: Any, b: Any, label: str, path: str, mode: str) -> None:
        """``mode`` is ``request`` or ``response``."""
        ra = a.get("$ref") if isinstance(a, dict) else None
        rb = b.get("$ref") if isinstance(b, dict) else None
        if ra or rb:
            seen = (ra, rb, mode)
            if seen in self._seen:
                return
            self._seen.add(seen)
        a = flatten(self.old, a)
        b = flatten(self.new, b)
        ka, kb = _kind(self.old, a), _kind(self.new, b)
        where = _at(label, path)
        if ka and kb and ka != kb:
            self.breaking.append(f"{where}: type changed from {ka} to {kb}")
            return
        if not isinstance(a, dict) or not isinstance(b, dict):
            return
        self._enum(a, b, where)
        if a.get("properties") or b.get("properties"):
            self._properties(a, b, label, path, mode)
        if ka == "array":
            self.schema(a.get("items", {}), b.get("items", {}), label, path + "[]", mode)
        ap_a, ap_b = a.get("additionalProperties"), b.get("additionalProperties")
        if isinstance(ap_a, dict) and isinstance(ap_b, dict):
            self.schema(ap_a, ap_b, label, path + "{}", mode)

    def _enum(self, a: dict[str, Any], b: dict[str, Any], where: str) -> None:
        va, vb = a.get("enum"), b.get("enum")
        if not isinstance(va, list) or not isinstance(vb, list):
            return
        for v in va:
            if v not in vb and v is not None:
                self.breaking.append(f"{where}: enum value `{v}` removed")
        added = [f"`{v}`" for v in vb if v not in va and v is not None]
        if added:
            self.notes.append(f"{where}: enum value {', '.join(added)} added")

    def _properties(self, a: dict[str, Any], b: dict[str, Any], label: str, path: str, mode: str) -> None:
        pa, pb = a.get("properties", {}), b.get("properties", {})
        req_a, req_b = set(a.get("required", [])), set(b.get("required", []))
        hidden = "readOnly" if mode == "request" else "writeOnly"
        for name, prop in pa.items():
            child = f"{path}.{name}" if path else name
            if self._flag(self.old, prop, hidden):
                continue
            if name not in pb or self._flag(self.new, pb[name], hidden):
                self.breaking.append(f"{_at(label, child)}: field removed")
                continue
            if mode == "request" and name in req_b and name not in req_a:
                self.breaking.append(f"{_at(label, child)}: now required")
            self.schema(prop, pb[name], label, child, mode)
        for name, prop in pb.items():
            if name in pa or self._flag(self.new, prop, hidden):
                continue
            child = f"{path}.{name}" if path else name
            if mode == "request" and name in req_b:
                self.breaking.append(f"{_at(label, child)}: new required field")
            else:
                self.notes.append(f"{_at(label, child)}: field added")

    @staticmethod
    def _flag(doc: Document, prop: Any, flag: str) -> bool:
        if isinstance(prop, dict) and prop.get(flag):
            return True
        target = doc.deref(prop)
        return isinstance(target, dict) and bool(target.get(flag))

    def parameters(self, pa: dict[str, dict[str, Any]], pb: dict[str, dict[str, Any]]) -> None:
        for key, p in pa.items():
            label = f"{p['in']} parameter `{p['name']}`"
            q = pb.get(key)
            if q is None:
                self.breaking.append(f"{label}: removed")
                continue
            if q.get("required") and not p.get("required"):
                self.breaking.append(f"{label}: now required")
            self.schema(p.get("schema", {}), q.get("schema", {}), label, "", "request")
        for key, q in pb.items():
            if key in pa:
                continue
            label = f"{q['in']} parameter `{q['name']}`"
            if q.get("required"):
                self.breaking.append(f"{label}: new required parameter")
            else:
                self.notes.append(f"{label}: added")

    def request_body(self, ra: Any, rb: Any) -> None:
        ra = self.old.deref(ra) if ra else None
        rb = self.new.deref(rb) if rb else None
        if ra is None and rb is None:
            return
        if rb is None:
            self.breaking.append("request body: removed")
            return
        cb = rb.get("content") or {}
        if ra is None:
            required = sorted({r for m in cb.values() for r in flatten(self.new, m.get("schema", {})).get("required", [])})
            if rb.get("required") and required:
                self.breaking.append(f"request body: added with required fields {', '.join(f'`{r}`' for r in required)}")
            else:
                self.notes.append("request body: added")
            return
        ca = ra.get("content") or {}
        for ct in ca:
            if ct not in cb:
                self.breaking.append(f"request body: content type `{ct}` removed")
        for ct in cb:
            if ct not in ca:
                self.notes.append(f"request body: content type `{ct}` added")
        for ct in ca:
            if ct in cb:
                self.schema(ca[ct].get("schema", {}), cb[ct].get("schema", {}), "request body", "", "request")

    def responses(self, oa: dict[str, Any], ob: dict[str, Any]) -> None:
        sa = {s: self.old.deref(r) for s, r in oa.get("responses", {}).items() if str(s).startswith("2")}
        sb = {s: self.new.deref(r) for s, r in ob.get("responses", {}).items() if str(s).startswith("2")}
        pairs = [(s, s) for s in sa if s in sb]
        if not pairs and len(sa) == 1 and len(sb) == 1:
            pairs = [(next(iter(sa)), next(iter(sb)))]
            self.notes.append(f"success status changed from {pairs[0][0]} to {pairs[0][1]}")
        else:
            for s in sa:
                if s not in sb:
                    self.notes.append(f"response {s}: removed")
            for s in sb:
                if s not in sa:
                    self.notes.append(f"response {s}: added")
        for s1, s2 in pairs:
            ca = sa[s1].get("content") or {} if isinstance(sa[s1], dict) else {}
            cb = sb[s2].get("content") or {} if isinstance(sb[s2], dict) else {}
            main_a = _main_type(ca)
            main_b = _main_type(cb)
            if main_a != main_b:
                self.breaking.append(f"response {s2}: content type changed from {main_a or 'none'} to {main_b or 'none'}")
            elif main_a is not None:
                self.schema(ca[main_a].get("schema", {}), cb[main_b].get("schema", {}), f"response {s2}", "", "response")


def _main_type(content: dict[str, Any]) -> str | None:
    if "application/json" in content:
        return "application/json"
    return next(iter(content), None)


# Documents ----------------------------------------------------------------------


def changed_operations(old: Document, new: Document, prefix: str) -> list[OpChange]:
    """Operations present in both versions whose parameters, request body or 2xx responses differ."""
    exact = Comparer(old, new)
    structural = Comparer(old, new, ignore_docs=True)
    old_ops, new_ops = operations(old, prefix), operations(new, prefix)
    out: list[OpChange] = []
    for key in sorted(set(old_ops) & set(new_ops)):
        (oa, ia), (ob, ib) = old_ops[key], new_ops[key]
        pa, pb = _parts(old, oa, ia), _parts(new, ob, ib)
        names = list(dict.fromkeys(list(pa) + list(pb)))
        differing = [n for n in names if not exact.equal(pa.get(n), pb.get(n), n == "parameters")]
        if not differing:
            continue
        docs_only = all(structural.equal(pa.get(n), pb.get(n), n == "parameters") for n in differing)
        change = OpChange(key, differing, docs_only)
        if not docs_only:
            d = Details(old, new)
            d.parameters(pa["parameters"], pb["parameters"])
            d.request_body(oa.get("requestBody"), ob.get("requestBody"))
            d.responses(oa, ob)
            change.breaking = list(dict.fromkeys(d.breaking))
            change.notes = list(dict.fromkeys(d.notes))
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
