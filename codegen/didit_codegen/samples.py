"""Sample values for generated tests, synthesised from the type model."""

from __future__ import annotations

import json
from typing import Any

from .model import (
    AnyT, ArrayT, DateTimeT, EnumT, FileT, FreeObjT, MapT, Model, ModelT, Scalar, Shape, ShapeT, T, UnionT,
)


class FileSample:
    """Marker for a file upload in request samples."""

    def __init__(self, field: str) -> None:
        self.field = field
        self.filename = f"{field}.jpg"
        self.content = f"fake-{field}-bytes"


UUID = "3f2c8a7e-5b1d-4c9a-8e6f-2a1b3c4d5e6f"


def scalar_sample(t: Scalar, wire: str, example: Any = None) -> Any:
    if t.php == "string":
        if isinstance(example, str):
            return example
        return {
            "uuid": UUID,
            "date": "2026-01-15",
            "email": "user@example.com",
            "uri": "https://example.com/callback",
            "ipv4": "203.0.113.10",
            "ip": "203.0.113.10",
            "decimal": "10.50",
            "byte": "ZmFrZQ==",
        }.get(t.fmt or "", f"{wire}-value")
    if t.php == "int":
        return example if isinstance(example, int) and not isinstance(example, bool) else 1
    if t.php == "float":
        if isinstance(example, (int, float)) and not isinstance(example, bool):
            return float(example)
        return 1.5
    if t.php == "bool":
        return example if isinstance(example, bool) else True
    raise ValueError(t.php)


def request_sample(t: T, wire: str, depth: int = 0) -> Any:
    if isinstance(t, Scalar):
        return scalar_sample(t, wire)
    if isinstance(t, DateTimeT):
        return "2026-01-15T10:30:00+00:00"
    if isinstance(t, EnumT):
        return t.enum.values[0]
    if isinstance(t, FileT):
        return FileSample(wire)
    if isinstance(t, ShapeT):
        return shape_sample(t.shape, depth + 1)
    if isinstance(t, ArrayT):
        return [request_sample(t.item, wire, depth + 1)]
    if isinstance(t, MapT):
        return {"key": request_sample(t.value, wire, depth + 1)}
    if isinstance(t, FreeObjT):
        return {"key": "value"}
    if isinstance(t, UnionT):
        # Prefer a non-file variant so JSON operations stay JSON.
        for v in t.variants:
            if not isinstance(v, FileT):
                return request_sample(v, wire, depth)
        return request_sample(t.variants[0], wire, depth)
    return f"{wire}-value"


def shape_sample(shape: Shape, depth: int) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for p in shape.props:
        if p.required or depth <= 2:
            out[p.wire] = request_sample(p.type, p.wire, depth)
    return out


def response_sample(t: T, wire: str, depth: int = 0) -> Any:
    if isinstance(t, Scalar):
        return scalar_sample(t, wire)
    if isinstance(t, DateTimeT):
        return "2026-01-15T10:30:00Z"
    if isinstance(t, EnumT):
        return t.enum.values[0]
    if isinstance(t, ModelT):
        return model_sample(t.model, depth + 1)
    if isinstance(t, ArrayT):
        return [response_sample(t.item, wire, depth + 1)] if depth < 4 else []
    if isinstance(t, MapT):
        return {"key": response_sample(t.value, wire, depth + 1)} if depth < 4 else {}
    if isinstance(t, FreeObjT):
        return {"key": "value"}
    if isinstance(t, UnionT):
        return response_sample(t.variants[0], wire, depth)
    return f"{wire}-value"


def model_sample(model: Model, depth: int = 0) -> dict[str, Any]:
    out: dict[str, Any] = {}
    if depth > 4:
        return out
    for p in model.props:
        out[p.wire] = response_sample(p.type, p.wire, depth)
    return out


# PHP literals -------------------------------------------------------------------


def php_literal(value: Any, indent: str = "") -> str:
    if isinstance(value, FileSample):
        return f"File::fromBytes({php_str(value.content)}, {php_str(value.filename)}, 'image/jpeg')"
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        text = repr(value)
        if "e" in text or "E" in text:
            mantissa, exp = text.lower().split("e")
            if "." not in mantissa:
                mantissa += ".0"
            return f"{mantissa}E{int(exp)}"
        return text
    if isinstance(value, str):
        return php_str(value)
    if isinstance(value, list):
        if not value:
            return "[]"
        inner = indent + "    "
        return "[\n" + "".join(f"{inner}{php_literal(v, inner)},\n" for v in value) + f"{indent}]"
    if isinstance(value, dict):
        if not value:
            return "[]"
        inner = indent + "    "
        return "[\n" + "".join(f"{inner}{php_str(str(k))} => {php_literal(v, inner)},\n" for k, v in value.items()) + f"{indent}]"
    raise TypeError(type(value))


def php_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def json_text(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def has_file(value: Any) -> bool:
    if isinstance(value, FileSample):
        return True
    if isinstance(value, list):
        return any(has_file(v) for v in value)
    return False
