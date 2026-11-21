"""Docblock text helpers."""

from __future__ import annotations

import re

MAX_SUMMARY = 600


def first_paragraph(text: str | None) -> str:
    if not text:
        return ""
    text = text.replace("\r\n", "\n").strip()
    para = re.split(r"\n\s*\n", text, maxsplit=1)[0]
    # DRF enum descriptions start with a bullet list; drop it.
    if para.lstrip().startswith("* `"):
        return ""
    para = " ".join(line.strip() for line in para.split("\n"))
    para = re.sub(r"\s+", " ", para).strip()
    if len(para) > MAX_SUMMARY:
        cut = para[:MAX_SUMMARY]
        dot = cut.rfind(". ")
        para = cut[: dot + 1] if dot > 200 else cut.rstrip() + "..."
    return sentence(para)


def first_sentence(text: str | None) -> str:
    para = first_paragraph(text)
    match = re.match(r"^(.+?[.!?])(\s|$)", para)
    return match.group(1) if match else para


def sentence(text: str) -> str:
    text = text.strip()
    if not text:
        return text
    text = text.replace("*/", "*&#47;")
    # No em or en dashes in generated docs.
    text = re.sub(r"\s*\u2014\s*", ", ", text).replace("\u2013", "-")
    if text[-1] not in ".!?:":
        text += "."
    return text


def wrap(text: str, width: int = 100) -> list[str]:
    words = text.split(" ")
    lines: list[str] = []
    current = ""
    for w in words:
        if current and len(current) + 1 + len(w) > width:
            lines.append(current)
            current = w
        else:
            current = f"{current} {w}" if current else w
    if current:
        lines.append(current)
    return lines


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def docblock(lines: list[str], indent: str = "") -> str:
    """A ``/** ... */`` block; an empty string in ``lines`` is a blank line."""
    collapsed: list[str] = []
    for line in lines:
        if line == "" and collapsed and collapsed[-1] == "":
            continue
        collapsed.append(line)
    lines = collapsed
    while lines and lines[-1] == "":
        lines.pop()
    while lines and lines[0] == "":
        lines.pop(0)
    if not lines:
        return ""
    out = [f"{indent}/**"]
    for line in lines:
        out.append(f"{indent} *" if line == "" else f"{indent} * {line}")
    out.append(f"{indent} */")
    return "\n".join(out) + "\n"


def annotation_text(text: str) -> str:
    """Description after ``@param $x``, written the way PHP-CS-Fixer's
    ``phpdoc_annotation_without_dot`` leaves it."""
    text = text.strip()
    if re.search(r"\.\s*$", text) and not re.search(r"\.(?!\s*$)", text):
        text = re.sub(r"\.\s*$", "", text)
        if len(text) > 1 and text[0].isupper() and (text[1].islower() or text[1] == " "):
            text = text[0].lower() + text[1:]
    return text
