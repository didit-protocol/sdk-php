"""Generated PHPUnit tests: one test per server operation (docs/DESIGN.md section 11).

Each test sends the operation through a mocked HTTP client and asserts the
method, the path (ids filled, application scope expanded), the query, the
headers, the body encoding and the parsed response. Request and response
values come from the spec's examples when they fit the schema, otherwise
from the schema itself.
"""

from __future__ import annotations

import re
from typing import Any

from . import naming
from .model import (
    ArrayT, DateTimeT, EnumT, FileT, FreeObjT, MapT, ModelT, OpModel, Scalar, ShapeT, T, UnionT,
)
from .php import HEADER, ResourceNode, walk
from .samples import (
    FileSample, has_file, json_text, model_sample, php_literal, php_str, request_sample, response_sample,
    scrub_credentials,
)

ORG_ID = "org-7f3a"
APP_ID = "app-91c2"


def emit(ops: list[OpModel], tree: ResourceNode) -> dict[str, str]:
    files: dict[str, str] = {}
    for node in walk(tree):
        if not node.ops:
            continue
        rel = naming.resource_class(node.path)
        ns_parts = rel.split("\\")
        namespace = "Didit\\Tests\\Generated" + ("\\" + "\\".join(ns_parts[:-1]) if len(ns_parts) > 1 else "")
        cls = ns_parts[-1] + "Test"
        files[f"tests/Generated/{rel.replace(chr(92), '/')}Test.php"] = _emit_class(node, namespace, cls)
    return files


def _emit_class(node: ResourceNode, namespace: str, cls: str) -> str:
    methods = []
    uses = {"Didit\\Tests\\Support\\GeneratedTestCase"}
    for om in node.ops:
        text, needed = _emit_test(om, node)
        methods.append(text)
        uses |= needed
    lines = [HEADER, f"namespace {namespace};", ""]
    for u in sorted(uses, key=str.lower):
        lines.append(f"use {u};")
    lines.append("")
    lines.append("/**")
    lines.append(f" * Generated tests for the `{node.path}` resource.")
    lines.append(" *")
    lines.append(" * @internal")
    lines.append(" */")
    lines.append(f"final class {cls} extends GeneratedTestCase")
    lines.append("{")
    lines.append("\n\n".join(methods))
    lines.append("}")
    return "\n".join(lines) + "\n"


def _accessor(om: OpModel) -> str:
    return "$this->client->" + "->".join(naming.camel(seg) for seg in om.op.naming.resource.split("."))


def _example(content: dict[str, Any]) -> Any:
    if "example" in content:
        return content["example"]
    examples = content.get("examples")
    if isinstance(examples, dict) and examples:
        first = next(iter(examples.values()))
        if isinstance(first, dict) and "value" in first:
            return first["value"]
    schema = content.get("schema")
    if isinstance(schema, dict) and "example" in schema:
        return schema["example"]
    return None


def _emit_test(om: OpModel, node: ResourceNode) -> tuple[str, set[str]]:
    o = om.op
    uses: set[str] = set()
    method = naming.camel(o.naming.method)
    body: list[str] = []

    # Path
    path_values = {pp.wire: (123 if pp.php == "int" else f"{pp.var}-1") for pp in om.path_params}
    expected_path = o.path
    for name, value in path_values.items():
        expected_path = expected_path.replace("{" + name + "}", str(value))
    expected_path = expected_path.replace("{organization_id}", ORG_ID).replace("{application_id}", APP_ID)

    # Params
    params: dict[str, Any] = {}
    expected_query: dict[str, str] = {}
    expected_headers: dict[str, str] = {}
    body_values: dict[str, Any] = {}
    if om.body is not None:
        body_values = _body_sample(om)
        params.update(body_values)
    for p in om.params:
        value = request_sample(p.type, p.key)
        params[p.key] = value
        if p.location == "query":
            expected_query[p.wire] = _query_text(value)
        else:
            expected_headers[p.wire] = str(value)

    use_multipart = "multipart" in om.body_types and ("json" not in om.body_types or any(has_file(v) for v in body_values.values()))
    if any(has_file(v) for v in params.values()):
        uses.add("Didit\\File")

    # Response
    queue, assertions, ret_uses = _response(om)
    uses |= ret_uses

    body.append(f"    public function test{naming.pascal(o.naming.method)}(): void")
    body.append("    {")
    body.append(f"        {queue}")
    body.append("")
    args = [php_literal(v, "        ") if isinstance(v, int) else php_str(str(v)) for v in path_values.values()]
    if om.body_alias is not None:
        args.append(php_literal(params, "        ") if params else "[]")
    call = f"{_accessor(om)}->{method}({', '.join(args)})"
    body.append(f"        $result = {call};")
    body.append("")
    body.append("        $request = $this->lastRequest();")
    body.append(f"        self::assertSame({php_str(o.http_method)}, $request->getMethod());")
    host = "apx.didit.me" if o.base == "account" else "verification.didit.me"
    body.append(f"        self::assertSame({php_str(host)}, $request->getUri()->getHost());")
    full_path = ("/auth/v2" if o.base == "account" else "") + expected_path
    body.append(f"        self::assertSame({php_str(full_path)}, $request->getUri()->getPath());")
    body.append(f"        self::assertSame({php_literal(expected_query, '        ')}, $this->queryParams($request));")
    if o.naming.auth == "api_key":
        body.append("        self::assertSame(self::API_KEY, $request->getHeaderLine('x-api-key'));")
    elif o.naming.auth == "account_token":
        body.append("        self::assertSame('Bearer ' . self::ACCOUNT_TOKEN, $request->getHeaderLine('Authorization'));")
        body.append("        self::assertSame('', $request->getHeaderLine('x-api-key'));")
    else:
        body.append("        self::assertSame('', $request->getHeaderLine('x-api-key'));")
        body.append("        self::assertSame('', $request->getHeaderLine('Authorization'));")
    for name, value in expected_headers.items():
        body.append(f"        self::assertSame({php_str(value)}, $request->getHeaderLine({php_str(name)}));")
    if om.accept != "application/json":
        body.append(f"        self::assertSame({php_str(om.accept)}, $request->getHeaderLine('Accept'));")

    if om.body_types or body_values:
        if use_multipart:
            expected = _multipart_expectation(body_values)
            body.append("        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));")
            body.append(f"        self::assertSame({php_literal(expected, '        ')}, $this->multipartFields($request));")
        else:
            body.append("        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));")
            json_expected = _json_expectation(body_values)
            body.append(f"        self::assertSame({php_str(json_text(json_expected) if json_expected else '{}')}, $this->canonicalBody($request));")
    else:
        body.append("        self::assertSame('', (string) $request->getBody());")

    body.extend(assertions)
    body.append("    }")
    return "\n".join(body), uses


def _body_sample(om: OpModel) -> dict[str, Any]:
    assert om.body is not None
    sample: dict[str, Any] = {}
    for p in om.body.props:
        sample[p.wire] = request_sample(p.type, p.wire, 1)
    return sample


def _query_text(value: Any) -> str:
    if value is True:
        return "true"
    if value is False:
        return "false"
    return str(value)


def _json_expectation(values: Any) -> Any:
    if isinstance(values, FileSample):
        raise ValueError("file in a JSON body")
    if isinstance(values, dict):
        return {k: _json_expectation(v) for k, v in values.items()}
    if isinstance(values, list):
        return [_json_expectation(v) for v in values]
    return values


def _multipart_expectation(values: dict[str, Any]) -> dict[str, list[str]]:
    out: dict[str, list[str]] = {}
    for key, value in values.items():
        if value is None:
            continue
        if isinstance(value, list) and all(not isinstance(v, (dict, list)) for v in value):
            out[key] = [_multipart_text(v) for v in value]
        else:
            out[key] = [_multipart_text(value)]
    return out


def _multipart_text(value: Any) -> str:
    if isinstance(value, FileSample):
        return f"file:{value.filename}:image/jpeg:{value.content}"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, (dict, list)):
        return json_text(value).replace("/", "/")
    if isinstance(value, float):
        return json_text(value)
    return str(value)


def _response(om: OpModel) -> tuple[str, list[str], set[str]]:
    r = om.response
    uses: set[str] = set()
    out: list[str] = []
    union = r.kind == "union"
    if union:
        r = r.variants[0]
        first = r.type.model.name if isinstance(r.type, ModelT) else None
        if first:
            out.append(f"        self::assertInstanceOf(Types\\{first}::class, $result);")
            uses.add("Didit\\Types")
    status = int(r.status or 200)
    if r.kind == "none":
        out.append(f"        self::assertSame({status}, $result->rawResponse()?->status);")
        return f"$this->queueEmpty({status});", out, uses
    if r.kind == "binary":
        ct = r.content_type or "application/octet-stream"
        out.append("        self::assertSame('binary-content', $result->contents());")
        out.append(f"        self::assertSame({php_str(ct)}, $result->contentType());")
        return f"$this->queueBinary({status}, 'binary-content', {php_str(ct)});", out, uses

    example = _response_example(om, r)
    if r.kind == "json":
        data = example if example is not None else {"key": "value"}
        out.append(f"        self::assertSame($this->decoded({php_str(json_text(data))}), $result->data);")
        return f"$this->queueJson({status}, {php_str(json_text(data))});", out, uses
    if r.kind == "page":
        item = r.type
        item_sample = response_sample(item, "item") if not isinstance(example, dict) else None
        data = example if isinstance(example, dict) and isinstance(example.get("results"), list) and example["results"] else {
            "count": 1, "next": None, "previous": None, "results": [item_sample],
        }
        data = dict(data)
        data["next"] = None
        out.append(f"        self::assertCount({len(data['results'])}, $result->results);")
        if isinstance(item, ModelT):
            out.append(f"        self::assertContainsOnlyInstancesOf(Types\\{item.model.name}::class, $result->results);")
            uses.add("Didit\\Types")
        out.append("        self::assertFalse($result->hasNextPage());")
        out.append(f"        self::assertSame($this->decoded({php_str(json_text(data))}), $result->toArray());")
        return f"$this->queueJson({status}, {php_str(json_text(data))});", out, uses
    if r.kind == "list":
        item = r.type
        data = example if isinstance(example, list) and example else [response_sample(item, "item")]
        if isinstance(item, ModelT):
            out.append(f"        self::assertCount({len(data)}, $result);")
            out.append(f"        self::assertContainsOnlyInstancesOf(Types\\{item.model.name}::class, $result->items);")
            uses.add("Didit\\Types")
        out.append(f"        self::assertSame($this->decoded({php_str(json_text(data))}), $result->toArray());")
        return f"$this->queueJson({status}, {php_str(json_text(data))});", out, uses
    if r.kind == "model":
        assert isinstance(r.type, ModelT)
        model = r.type.model
        data = example if isinstance(example, dict) else model_sample(model)
        out.append(f"        self::assertSame({status}, $result->rawResponse()?->status);")
        out.append(f"        self::assertSame($this->decoded({php_str(json_text(data))}), $result->toArray());")
        check = _scalar_check(model, data)
        if check:
            out.append(check)
        return f"$this->queueJson({status}, {php_str(json_text(data))});", out, uses
    raise ValueError(r.kind)


def _response_example(om: OpModel, r) -> Any:
    o = om.op
    resp = o.op.get("responses", {}).get(r.status or "200")
    if resp is None:
        return None
    resp = o.doc.deref(resp)
    content = (resp.get("content") or {}).get("application/json")
    if not content:
        return None
    ex = _example(content)
    if ex is None and isinstance(content.get("schema"), dict) and "$ref" in content["schema"]:
        target = o.doc.deref(content["schema"])
        if isinstance(target, dict) and "example" in target:
            ex = target["example"]
    return scrub_credentials(ex)


def _scalar_check(model, data: dict[str, Any]) -> str | None:
    for p in model.props:
        if p.wire not in data:
            continue
        value = data[p.wire]
        t = p.type
        if isinstance(t, (Scalar, EnumT)):
            base = t.php if isinstance(t, Scalar) else t.enum.base
            if base == "string" and isinstance(value, str):
                return f"        self::assertSame({php_str(value)}, $result->{p.name});"
            if base == "int" and isinstance(value, int) and not isinstance(value, bool):
                return f"        self::assertSame({value}, $result->{p.name});"
            if base == "bool" and isinstance(value, bool):
                return f"        self::assert{'True' if value else 'False'}($result->{p.name});"
        if isinstance(t, DateTimeT) and isinstance(value, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", value):
            return f"        self::assertInstanceOf(\\DateTimeImmutable::class, $result->{p.name});"
    return None
