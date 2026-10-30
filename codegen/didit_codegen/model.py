"""Turns OpenAPI schemas into the SDK's type model (docs/DESIGN.md section 5.1).

Response objects become read-only classes (``Model``); request objects become
PHPStan array shapes (``Shape``); enums become classes of string constants
(``Enum``). Names follow section 5.1: a ``$ref`` component keeps its name, an
inline body is ``<Resource><Method>Params`` / ``<Resource><Method>Response``
and nested inline objects append the property name.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from . import naming
from .spec import Document, Operation, SpecError

REF_PREFIX = "#/components/schemas/"


# Types -------------------------------------------------------------------


@dataclass
class T:
    nullable: bool = field(default=False, kw_only=True)


@dataclass
class Scalar(T):
    php: str  # string | int | float | bool
    fmt: str | None = None


@dataclass
class DateTimeT(T):
    pass


@dataclass
class EnumT(T):
    enum: "Enum"


@dataclass
class ModelT(T):
    model: "Model"


@dataclass
class ShapeT(T):
    shape: "Shape"


@dataclass
class ArrayT(T):
    item: T


@dataclass
class MapT(T):
    value: T


@dataclass
class FreeObjT(T):
    pass


@dataclass
class AnyT(T):
    # The spec's example is a JSON object, so an empty PHP array means `{}`.
    object_example: bool = False


@dataclass
class UnionT(T):
    variants: list[T]


@dataclass
class FileT(T):
    pass


@dataclass
class Prop:
    wire: str
    name: str  # PHP property name (responses) or array key (requests)
    type: T
    required: bool
    description: str
    deprecated: bool = False


@dataclass
class Model:
    name: str
    description: str
    props: list[Prop] = field(default_factory=list)
    origin: str = ""


@dataclass
class Shape:
    name: str
    description: str
    props: list[Prop] = field(default_factory=list)
    origin: str = ""


@dataclass
class Enum:
    name: str
    description: str
    values: list[Any]
    base: str  # string | int
    origin: str = ""


# Builder -----------------------------------------------------------------


def _is_objectish(s: dict[str, Any]) -> bool:
    return bool(s.get("properties")) or (s.get("type") == "object" and "additionalProperties" not in s and not s.get("enum"))


class Registry:
    """All generated types, shared by every operation."""

    def __init__(self) -> None:
        self.models: dict[str, Model] = {}
        self.shapes: dict[str, Shape] = {}
        self.enums: dict[str, Enum] = {}
        self._by_origin: dict[tuple[str, str], Any] = {}
        self._stack: list[str] = []
        self.notes: list[str] = []

    # names ---------------------------------------------------------------

    def _unique(self, table: dict[str, Any], name: str, origin: str) -> str:
        existing = table.get(name)
        if existing is None:
            return name
        if existing.origin == origin:
            return name
        n = 2
        while f"{name}{n}" in table and table[f"{name}{n}"].origin != origin:
            n += 1
        self.notes.append(f"type name {name} is used twice; {origin} became {name}{n}")
        return f"{name}{n}"

    def _all_names(self) -> dict[str, Any]:
        merged: dict[str, Any] = {}
        merged.update(self.models)
        merged.update(self.enums)
        return merged

    # entry points --------------------------------------------------------

    def convert(self, doc: Document, schema: Any, hint: str, mode: str, origin: str) -> T:
        """``mode`` is ``response`` or ``request``."""
        return self._convert(doc, schema, hint, mode, origin)

    # core ----------------------------------------------------------------

    def _convert(self, doc: Document, schema: Any, hint: str, mode: str, origin: str) -> T:
        if schema is None or schema is True or schema == {}:
            return AnyT()
        if not isinstance(schema, dict):
            return AnyT()

        nullable = bool(schema.get("nullable"))

        if "$ref" in schema:
            t = self._convert_ref(doc, schema["$ref"], hint, mode, origin)
            t.nullable = t.nullable or nullable
            return t

        all_of = schema.get("allOf")
        if isinstance(all_of, list) and all_of:
            if len(all_of) == 1:
                t = self._convert(doc, all_of[0], hint, mode, origin)
                t.nullable = t.nullable or nullable
                return t
            merged = self._merge_all_of(doc, schema)
            t = self._convert(doc, merged, hint, mode, origin)
            t.nullable = t.nullable or nullable
            return t

        variants = schema.get("oneOf") or schema.get("anyOf")
        if isinstance(variants, list) and variants:
            t = self._convert_one_of(doc, schema, variants, hint, mode, origin)
            t.nullable = t.nullable or nullable
            return t

        if "enum" in schema:
            values = [v for v in schema["enum"] if v is not None]
            if values and all(isinstance(v, str) for v in values):
                return EnumT(self._enum(hint, schema, values, "string", origin), nullable=nullable)
            if values and all(isinstance(v, int) and not isinstance(v, bool) for v in values):
                return EnumT(self._enum(hint, schema, values, "int", origin), nullable=nullable)

        typ = schema.get("type")
        if typ == "array":
            item = self._convert(doc, schema.get("items", {}), hint, mode, origin + "[]")
            return ArrayT(item, nullable=nullable)
        if typ == "object" or schema.get("properties"):
            if schema.get("properties"):
                return self._object(doc, hint, schema, mode, origin, nullable)
            ap = schema.get("additionalProperties")
            if isinstance(ap, dict) and ap:
                value = self._convert(doc, ap, hint + "Value", mode, origin + "{}")
                return MapT(value, nullable=nullable)
            return FreeObjT(nullable=nullable)
        if typ == "string":
            fmt = schema.get("format")
            if fmt == "date-time":
                return DateTimeT(nullable=nullable)
            if fmt == "binary":
                return FileT(nullable=nullable) if mode == "request" else Scalar("string", fmt, nullable=nullable)
            return Scalar("string", fmt, nullable=nullable)
        if typ == "integer":
            return Scalar("int", schema.get("format"), nullable=nullable)
        if typ == "number":
            return Scalar("float", schema.get("format"), nullable=nullable)
        if typ == "boolean":
            return Scalar("bool", nullable=nullable)
        return AnyT(nullable=nullable, object_example=isinstance(schema.get("example"), dict))

    def _convert_ref(self, doc: Document, ref: str, hint: str, mode: str, origin: str) -> T:
        target = doc.resolve(ref)
        if not ref.startswith(REF_PREFIX):
            # A reference into another operation: named after where it is used first.
            return self._convert(doc, target, hint, mode, f"{doc.name}:{ref}")
        component = ref[len(REF_PREFIX):]
        name = naming.class_name(component)
        target_d = doc.deref(target)
        if not isinstance(target_d, dict):
            return AnyT()
        is_object = bool(target_d.get("properties")) or (
            isinstance(target_d.get("allOf"), list) and len(target_d["allOf"]) > 1
        ) or (
            isinstance(target_d.get("oneOf"), list)
            and all(_is_objectish(doc.deref(v)) for v in target_d["oneOf"])
        )
        comp_origin = f"{doc.name}:{ref}"
        if is_object:
            if mode == "request" and comp_origin in self._stack:
                # Recursive request shapes cannot be PHPStan aliases.
                return FreeObjT()
            return self._convert(doc, target_d, name, mode, comp_origin)
        return self._convert(doc, target_d, name, mode, comp_origin)

    def _merge_all_of(self, doc: Document, schema: dict[str, Any]) -> dict[str, Any]:
        props: dict[str, Any] = {}
        required: list[str] = []
        description = schema.get("description", "")
        for part in schema["allOf"]:
            part = self._flatten(doc, part)
            props.update(part.get("properties", {}))
            required.extend(part.get("required", []))
            if not description:
                description = part.get("description", "")
        out = {k: v for k, v in schema.items() if k not in ("allOf",)}
        out.update({"type": "object", "properties": props, "required": required, "description": description})
        return out

    def _flatten(self, doc: Document, part: Any) -> dict[str, Any]:
        part = doc.deref(part)
        if isinstance(part, dict) and isinstance(part.get("allOf"), list):
            return self._merge_all_of(doc, part)
        return part if isinstance(part, dict) else {}

    def _convert_one_of(self, doc: Document, schema: dict[str, Any], variants: list[Any], hint: str, mode: str, origin: str) -> T:
        resolved = [self._flatten(doc, v) for v in variants]
        objects = [v for v in resolved if _is_objectish(v)]
        others = [variants[i] for i, v in enumerate(resolved) if not _is_objectish(v)]
        out: list[T] = []
        if objects:
            if len(objects) == 1 and len(variants) > 1 and "$ref" in variants[resolved.index(objects[0])]:
                out.append(self._convert(doc, variants[resolved.index(objects[0])], hint, mode, origin))
            else:
                merged_props: dict[str, Any] = {}
                for obj in objects:
                    for key, prop in obj.get("properties", {}).items():
                        if key in merged_props:
                            merged_props[key] = self._merge_prop(doc, merged_props[key], prop)
                        else:
                            merged_props[key] = prop
                required = set.intersection(*(set(o.get("required", [])) for o in objects)) if objects else set()
                merged = {
                    "type": "object",
                    "description": schema.get("description") or objects[0].get("description", ""),
                    "properties": merged_props,
                    "required": [k for k in merged_props if k in required],
                }
                out.append(self._object(doc, hint, merged, mode, origin, False))
        for other in others:
            out.append(self._convert(doc, other, hint, mode, origin))
        if len(out) == 1:
            return out[0]
        return UnionT(out)

    def _merge_prop(self, doc: Document, a: Any, b: Any) -> Any:
        da, db = doc.deref(a), doc.deref(b)
        if isinstance(da, dict) and isinstance(db, dict) and "enum" in da and "enum" in db:
            values = list(da["enum"]) + [v for v in db["enum"] if v not in da["enum"]]
            merged = dict(da)
            merged["enum"] = values
            return merged
        return a

    def _object(self, doc: Document, hint: str, schema: dict[str, Any], mode: str, origin: str, nullable: bool) -> T:
        key = (origin, mode)
        if key in self._by_origin:
            cached = self._by_origin[key]
            return ModelT(cached, nullable=nullable) if mode == "response" else ShapeT(cached, nullable=nullable)

        table: dict[str, Any] = self.models if mode == "response" else self.shapes
        name = self._unique(table if mode == "request" else self._all_names(), hint, origin)
        description = schema.get("description", "") or schema.get("title", "")
        holder: Model | Shape = Model(name, description, origin=origin) if mode == "response" else Shape(name, description, origin=origin)
        table[name] = holder
        self._by_origin[key] = holder
        self._stack.append(origin)
        try:
            required = set(schema.get("required", []))
            used: set[str] = set()
            for wire, prop_schema in schema.get("properties", {}).items():
                ps = doc.deref(prop_schema) if isinstance(prop_schema, dict) else {}
                if not isinstance(ps, dict):
                    ps = {}
                if mode == "response" and (ps.get("writeOnly") or (isinstance(prop_schema, dict) and prop_schema.get("writeOnly"))):
                    continue
                if mode == "request" and (ps.get("readOnly") or (isinstance(prop_schema, dict) and prop_schema.get("readOnly"))):
                    continue
                t = self._convert(doc, prop_schema, name + naming.pascal(wire), mode, f"{origin}.{wire}")
                if mode == "response":
                    php_name = naming.property_name(wire)
                    base = php_name
                    n = 2
                    while php_name.lower() in used:
                        php_name = f"{base}{n}"
                        n += 1
                    used.add(php_name.lower())
                else:
                    php_name = wire
                description = ""
                if isinstance(prop_schema, dict):
                    description = prop_schema.get("description", "")
                if not description:
                    description = ps.get("description", "")
                deprecated = bool(ps.get("deprecated") or (isinstance(prop_schema, dict) and prop_schema.get("deprecated")))
                holder.props.append(Prop(wire, php_name, t, wire in required, description, deprecated))
        finally:
            self._stack.pop()
        return ModelT(holder, nullable=nullable) if mode == "response" else ShapeT(holder, nullable=nullable)

    def _enum(self, hint: str, schema: dict[str, Any], values: list[Any], base: str, origin: str) -> Enum:
        key = (origin, "enum")
        if key in self._by_origin:
            return self._by_origin[key]
        # An enum that is identical to one already generated under the same name is shared.
        existing = self.enums.get(hint)
        if existing is not None and existing.values == values:
            self._by_origin[key] = existing
            return existing
        name = self._unique(self._all_names(), hint, origin)
        enum = Enum(name, schema.get("description", ""), values, base, origin=origin)
        self.enums[name] = enum
        self._by_origin[key] = enum
        return enum


# Operations --------------------------------------------------------------


@dataclass
class Param:
    key: str  # key in the PHP $params array
    wire: str  # query parameter or header name
    location: str  # query | header
    type: T
    required: bool
    description: str


@dataclass
class PathParam:
    wire: str
    var: str  # PHP variable name
    php: str  # string | int
    description: str


@dataclass
class Response:
    kind: str  # model | page | list | json | none | binary | union
    type: T | None = None
    status: str | None = None
    content_type: str | None = None
    variants: list["Response"] = field(default_factory=list)


@dataclass
class OpModel:
    op: Operation
    path_params: list[PathParam]
    params: list[Param]
    body: Shape | None
    body_alias: str | None  # PHPStan alias used for $params
    body_types: list[str]
    body_required: bool
    response: Response
    accept: str


SAFE_HEADER_SKIP = {"content-type", "accept", "x-api-key", "user-agent"}


def build_operation(reg: Registry, o: Operation) -> OpModel:
    prefix = naming.type_prefix(o.naming.resource, o.naming.method)
    doc = o.doc

    path_params: list[PathParam] = []
    query: list[Param] = []
    headers: list[Param] = []
    for p in o.parameters:
        schema = doc.deref(p.get("schema", {})) or {}
        if p["in"] == "path":
            if o.naming.scope == "application" and p["name"] in ("organization_id", "application_id"):
                continue
            php = "int" if schema.get("type") == "integer" else "string"
            path_params.append(PathParam(p["name"], naming.property_name(p["name"]), php, p.get("description", "")))
        elif p["in"] == "query":
            t = reg.convert(doc, p.get("schema", {}), prefix + "Params" + naming.pascal(p["name"]), "request", f"{o.key}#query.{p['name']}")
            query.append(Param(p["name"], p["name"], "query", t, bool(p.get("required")), p.get("description", "")))
        elif p["in"] == "header":
            if p["name"].lower() in SAFE_HEADER_SKIP:
                continue
            key = naming.camel(p["name"])
            key = "_".join(w.lower() for w in p["name"].replace("_", "-").split("-") if w)
            # The SDK authenticates with the API key, so a credential header is never required.
            required = bool(p.get("required")) and p["name"].lower() != "authorization"
            headers.append(Param(key, p["name"], "header", Scalar("string"), required, p.get("description", "")))
    order = {name: i for i, name in enumerate(_placeholders(o.path))}
    path_params.sort(key=lambda pp: order.get(pp.wire, 0))
    missing = [n for n in _placeholders(o.path) if n not in {pp.wire for pp in path_params} and not (o.naming.scope == "application" and n in ("organization_id", "application_id"))]
    for n in missing:
        path_params.append(PathParam(n, naming.property_name(n), "string", ""))

    body: Shape | None = None
    body_alias: str | None = None
    body_types: list[str] = []
    body_required = False
    rb = o.op.get("requestBody")
    if rb:
        rb = doc.deref(rb)
        body_required = bool(rb.get("required"))
        content = rb.get("content", {})
        merged_schema, ref_name = _merge_body_schemas(doc, content)
        for ct in content:
            if ct == "application/json":
                body_types.append("json")
            elif ct == "multipart/form-data":
                body_types.append("multipart")
            elif ct == "application/x-www-form-urlencoded":
                body_types.append("form")
        if merged_schema is not None:
            if ref_name is not None:
                t = reg.convert(doc, {"$ref": REF_PREFIX + ref_name}, prefix + "Params", "request", o.key + "#body")
            else:
                t = reg.convert(doc, merged_schema, prefix + "Params", "request", o.key + "#body")
            if isinstance(t, ShapeT):
                body = t.shape
    order_types = ["json", "multipart", "form"]
    body_types = sorted(set(body_types), key=order_types.index)

    # Query or header keys that collide with body fields get a suffix.
    body_keys = {p.wire for p in body.props} if body else set()
    for p in query + headers:
        if p.key in body_keys:
            reg.notes.append(f"{o.key}: {p.location} parameter {p.wire} is also a body field; exposed as {p.key}_{p.location}")
            p.key = f"{p.key}_{p.location}"

    params = query + headers
    if body is not None and not params and _is_component(body):
        body_alias = body.name
    elif body is not None or params:
        body_alias = prefix + "Params"

    response = _response(reg, o, prefix)
    accept = "application/json"
    if response.kind == "binary" and response.content_type:
        accept = f"{response.content_type}, application/json"

    return OpModel(o, path_params, params, body, body_alias, body_types, body_required, response, accept)


def _is_component(shape: Shape) -> bool:
    return ":#/components/schemas/" in shape.origin


def _placeholders(path: str) -> list[str]:
    import re

    return re.findall(r"\{([^}]+)\}", path)


def _merge_body_schemas(doc: Document, content: dict[str, Any]) -> tuple[dict[str, Any] | None, str | None]:
    schemas = []
    refs = set()
    for ct in ("application/json", "multipart/form-data", "application/x-www-form-urlencoded"):
        if ct in content and "schema" in content[ct]:
            raw = content[ct]["schema"]
            if isinstance(raw, dict) and "$ref" in raw:
                refs.add(raw["$ref"])
            schemas.append(raw)
    if not schemas:
        return None, None
    if len(refs) == 1 and all(isinstance(s, dict) and s.get("$ref") in refs for s in schemas):
        ref = next(iter(refs))
        if ref.startswith(REF_PREFIX):
            return doc.deref(schemas[0]), ref[len(REF_PREFIX):]
    if len(schemas) == 1:
        return schemas[0], None
    props: dict[str, Any] = {}
    required_sets = []
    for raw in schemas:
        s = doc.deref(raw)
        if isinstance(s, dict) and ("oneOf" in s or "anyOf" in s):
            # A content type that accepts more than one shape (an object or a
            # bare array, say) contributes the properties of every branch; a
            # key is required only when every branch requires it.
            branches = s.get("oneOf") if "oneOf" in s else s.get("anyOf")
            flat: dict[str, Any] = {}
            branch_required: list[set[str]] = []
            for branch in branches if isinstance(branches, list) else []:
                b = doc.deref(branch)
                if not isinstance(b, dict):
                    continue
                for key, prop in b.get("properties", {}).items():
                    flat.setdefault(key, prop)
                branch_required.append(set(b.get("required", [])))
            s = {"properties": flat,
                 "required": sorted(set.intersection(*branch_required)) if branch_required else [],
                 "description": s.get("description", "")}
        if isinstance(s, dict) and isinstance(s.get("allOf"), list):
            parts = [doc.deref(x) for x in s["allOf"]]
            s = {"properties": {k: v for p in parts for k, v in p.get("properties", {}).items()},
                 "required": [r for p in parts for r in p.get("required", [])], "description": s.get("description", "")}
        for key, prop in (s.get("properties", {}) if isinstance(s, dict) else {}).items():
            existing = props.get(key)
            if existing is None:
                props[key] = prop
            else:
                ea, eb = doc.deref(existing), doc.deref(prop)
                # A field that is a URL/string in JSON and a file in multipart accepts both.
                if eb.get("format") == "binary" and ea.get("format") != "binary":
                    props[key] = {"oneOf": [existing, prop], "description": ea.get("description", "")}
                elif ea.get("format") == "binary" and eb.get("format") != "binary":
                    props[key] = {"oneOf": [existing, prop], "description": ea.get("description", "")}
        required_sets.append(set(s.get("required", [])) if isinstance(s, dict) else set())
    required = set.intersection(*required_sets) if required_sets else set()
    first = doc.deref(schemas[0])
    description = first.get("description", "") if isinstance(first, dict) else ""
    return {"type": "object", "properties": props, "required": [k for k in props if k in required], "description": description}, None


def _response(reg: Registry, o: Operation, prefix: str) -> Response:
    doc = o.doc
    candidates: list[Response] = []
    seen_schemas: list[Any] = []
    statuses = sorted(c for c in o.op.get("responses", {}) if c.startswith("2"))
    distinct: list[tuple[str, str, Any]] = []
    for status in statuses:
        r = doc.deref(o.op["responses"][status])
        content = r.get("content") or {}
        if not content:
            distinct.append((status, "", None))
            continue
        if "application/json" in content:
            schema = content["application/json"].get("schema")
            distinct.append((status, "application/json", schema))
        else:
            ct = next(iter(content))
            distinct.append((status, ct, content[ct].get("schema")))

    unique: list[tuple[str, str, Any]] = []
    for status, ct, schema in distinct:
        if any(ct == u[1] and schema == u[2] for u in unique):
            continue
        unique.append((status, ct, schema))

    multiple = len(unique) > 1
    for status, ct, schema in unique:
        hint = prefix + "Response" + (status if multiple else "")
        candidates.append(_one_response(reg, doc, o, status, ct, schema, hint))
        seen_schemas.append(schema)

    if not candidates:
        return Response("none")
    if len(candidates) == 1:
        return candidates[0]
    # A body-less status next to a JSON one: the JSON type decides.
    with_body = [c for c in candidates if c.kind != "none"]
    if len(with_body) == 1:
        return with_body[0]
    return Response("union", variants=candidates)


def _one_response(reg: Registry, doc: Document, o: Operation, status: str, ct: str, schema: Any, hint: str) -> Response:
    if ct == "":
        # 204 has no body; any other status documented without a schema is read as raw JSON.
        return Response("none" if status in ("204", "205") else "json", status=status)
    if ct != "application/json":
        return Response("binary", status=status, content_type=ct)
    if schema is None:
        return Response("json", status=status)
    s = doc.deref(schema)
    if isinstance(s, dict):
        props = s.get("properties") or {}
        if {"count", "results"} <= set(props) and {"next", "previous"} <= set(props) and o.http_method == "GET":
            items = reg.convert(doc, props["results"], hint + "Results" if "$ref" not in schema else naming.class_name(schema["$ref"].rsplit("/", 1)[-1]) + "Results", "response", o.key + "#response.results")
            if isinstance(items, ArrayT):
                return Response("page", type=items.item, status=status)
    t = reg.convert(doc, schema, hint, "response", o.key + "#response" + status)
    if isinstance(t, ModelT):
        return Response("model", type=t, status=status)
    if isinstance(t, ArrayT):
        return Response("list", type=t.item, status=status)
    return Response("json", status=status)


def build(reg: Registry, operations: list[Operation]) -> list[OpModel]:
    out = []
    for o in operations:
        try:
            out.append(build_operation(reg, o))
        except SpecError:
            raise
        except Exception as exc:  # pragma: no cover - surfaced with context
            raise SpecError(f"{o.key}: {exc}") from exc
    return out
