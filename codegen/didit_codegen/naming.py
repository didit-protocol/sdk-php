"""Name conversions (docs/DESIGN.md sections 4 and 5.1)."""

from __future__ import annotations

import re

RESOURCE_RE = re.compile(r"^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$")
METHOD_RE = re.compile(r"^[a-z][a-z0-9_]*$")

# Names PHP reserves for classes (case-insensitive).
PHP_RESERVED_CLASS_NAMES = {
    "abstract", "and", "array", "as", "bool", "break", "callable", "case", "catch", "class", "clone",
    "const", "continue", "declare", "default", "do", "echo", "else", "elseif", "empty", "enddeclare",
    "endfor", "endforeach", "endif", "endswitch", "endwhile", "enum", "eval", "exit", "extends", "false",
    "final", "finally", "float", "fn", "for", "foreach", "function", "global", "goto", "if", "implements",
    "include", "instanceof", "insteadof", "int", "interface", "isset", "iterable", "list", "match", "mixed",
    "namespace", "never", "new", "null", "numeric", "object", "or", "parent", "print", "private",
    "protected", "public", "readonly", "require", "resource", "return", "self", "static", "string",
    "switch", "throw", "trait", "true", "try", "unset", "use", "var", "void", "while", "xor", "yield",
}


def _words(name: str) -> list[str]:
    return [w for w in re.split(r"[^A-Za-z0-9]+", name) if w]


def pascal(name: str) -> str:
    """``id_verification`` -> ``IdVerification``; inner capitals are kept."""
    return "".join(w[0].upper() + w[1:] for w in _words(name))


def camel(name: str) -> str:
    """``session_id`` -> ``sessionId``; a leading acronym is lowered (``URL`` -> ``url``)."""
    p = pascal(name)
    if not p:
        return p
    run = 0
    while run < len(p) and p[run].isupper():
        run += 1
    if run == len(p):
        return p.lower()
    if run > 1:
        return p[: run - 1].lower() + p[run - 1 :]
    return p[0].lower() + p[1:]


def property_name(wire: str) -> str:
    """PHP property name for a JSON field."""
    name = camel(wire)
    if not name:
        name = "value"
    if name[0].isdigit():
        name = "field" + name[0].upper() + name[1:]
    return name


def class_name(name: str) -> str:
    """A PHP class name; reserved words get a ``Model`` suffix."""
    out = pascal(name)
    if out and out[0].isdigit():
        out = "Type" + out
    if out.lower() in PHP_RESERVED_CLASS_NAMES:
        out += "Model"
    return out


def resource_class(resource: str) -> str:
    """``cases.routing_rules`` -> ``Cases\\RoutingRules`` (relative to ``Didit\\Resources``)."""
    return "\\".join(class_name(seg) for seg in resource.split("."))


def type_prefix(resource: str, method: str) -> str:
    """``sessions.imports`` + ``create`` -> ``SessionsImportsCreate``."""
    return "".join(pascal(seg) for seg in resource.split(".")) + pascal(method)


def enum_constant(value: object, used: set[str]) -> str:
    """``in_review`` / ``In Review`` / ``inReview`` -> ``IN_REVIEW``; unique within ``used``."""
    text = str(value)
    text = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", text)
    text = re.sub(r"[^A-Za-z0-9]+", "_", text).strip("_").upper()
    if not text:
        text = "EMPTY"
    if text[0].isdigit():
        text = "VALUE_" + text
    if text == "CLASS":
        text = "CLASS_"
    base = text
    n = 2
    while text in used:
        text = f"{base}_{n}"
        n += 1
    used.add(text)
    return text
