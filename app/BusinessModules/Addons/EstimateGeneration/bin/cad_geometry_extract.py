#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any, Iterable

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

EXPECTED_LIBREDWG_VERSION = "0.13.4"
SUPPORTED = {
    "LINE",
    "ARC",
    "CIRCLE",
    "LWPOLYLINE",
    "POLYLINE",
    "INSERT",
    "TEXT",
    "MTEXT",
    "DIMENSION",
}


class SafeFailure(Exception):
    def __init__(
        self,
        code: str,
        retryable: bool = False,
        context: dict[str, Any] | None = None,
    ):
        super().__init__(code)
        self.code = code
        self.retryable = retryable
        self.context = context or {}


def sha256_file(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return "sha256:" + digest.hexdigest()


def number(value: Any) -> float:
    result = round(float(value), 6)
    if not math.isfinite(result):
        raise SafeFailure("cad_number_invalid")
    return result


def point(value: Any) -> list[float]:
    return [number(value[0]), number(value[1])]


def handle(value: Any) -> str:
    if isinstance(value, list) and value:
        return format(int(value[-1]), "X")
    return str(value or "0")


def source_unit(code: Any) -> tuple[str | None, str]:
    unit = {1: "in", 2: "ft", 4: "mm", 5: "cm", 6: "m"}.get(int(code or 0))
    return unit, "confirmed" if unit else "unknown"


def entity_handle(
    entity: Any, lineage: list[str], source_member_handle: str | None = None
) -> str:
    own = source_member_handle or str(entity.dxf.handle or entity.dxftype())
    return "/".join([*lineage, own])


def matrix_payload(matrix: Any) -> list[list[float]]:
    return [[number(value) for value in row] for row in matrix.rows()]


def map_dxf_entity(
    entity: Any,
    lineage: list[str],
    block: str | None,
    layout: str,
    source_member_handle: str | None = None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None, dict[str, Any] | None]:
    entity_type = entity.dxftype()
    member_handle = source_member_handle or str(entity.dxf.handle or entity_type)
    item_handle = entity_handle(entity, lineage, member_handle)
    layer = str(entity.dxf.get("layer", "0"))
    owner = lineage[-1] if lineage else layout
    base: dict[str, Any] = {
        "handle": item_handle,
        "type": entity_type.lower(),
        "layer": layer,
        "layout": layout,
        "owner": owner,
        "source_lineage": [*lineage, member_handle],
        "source_member_handle": member_handle,
    }
    if block is not None:
        base["block"] = block
    if entity_type == "LINE":
        base["points"] = [point(entity.dxf.start), point(entity.dxf.end)]
        return base, None, None
    if entity_type in {"LWPOLYLINE", "POLYLINE"}:
        vertices: Iterable[Any] = (
            entity.get_points("xy")
            if entity_type == "LWPOLYLINE"
            else [vertex.dxf.location for vertex in entity.vertices]
        )
        base["points"] = [point(vertex) for vertex in vertices]
        base["closed"] = bool(
            entity.closed if entity_type == "LWPOLYLINE" else entity.is_closed
        )
        if len(base["points"]) < 2:
            raise SafeFailure("cad_required_entity_incomplete")
        return base, None, None
    if entity_type in {"ARC", "CIRCLE"}:
        base["center"] = point(entity.dxf.center)
        base["radius"] = number(entity.dxf.radius)
        if entity_type == "ARC":
            base["start_angle"] = number(entity.dxf.start_angle)
            base["end_angle"] = number(entity.dxf.end_angle)
        return base, None, None
    if entity_type in {"TEXT", "MTEXT"}:
        value = str(entity.dxf.text if entity_type == "TEXT" else entity.text)
        text = {
            "handle": item_handle,
            "type": entity_type.lower(),
            "layer": layer,
            "text": value,
            "position": point(entity.dxf.insert),
            "layout": layout,
            "owner": owner,
            "source_lineage": [*lineage, member_handle],
            "source_member_handle": member_handle,
            "transform": (
                [number(value) for value in entity.get_wcs_transform().m]
                if hasattr(entity, "get_wcs_transform")
                else [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]
            ),
        }
        if block is not None:
            text["block"] = block
        return None, text, None
    if entity_type == "DIMENSION":
        points = []
        for name in ("defpoint", "defpoint2", "defpoint3", "defpoint4"):
            value = entity.dxf.get(name, None)
            if value is not None:
                points.append(point(value))
        if len(points) < 2:
            raise SafeFailure("cad_required_entity_incomplete")
        dimension = {
            "handle": item_handle,
            "type": "dimension",
            "layer": layer,
            "text": str(entity.dxf.get("text", "")),
            "layout": layout,
            "owner": owner,
            "source_lineage": [*lineage, member_handle],
            "source_member_handle": member_handle,
            "definition_points": points,
        }
        if block is not None:
            dimension["block"] = block
        return None, None, dimension
    raise SafeFailure("cad_unsupported_entities")


def expand_insert(
    insert: Any,
    lineage: list[str],
    depth: int,
    max_depth: int,
    entities: list[dict[str, Any]],
    texts: list[dict[str, Any]],
    dimensions: list[dict[str, Any]],
    layout: str,
    source_member_handle: str | None = None,
) -> None:
    if depth > max_depth:
        raise SafeFailure("cad_block_depth_exceeded")
    member_handle = source_member_handle or str(insert.dxf.handle or insert.dxf.name)
    insert_handle = entity_handle(insert, lineage, member_handle)
    own_lineage = [*lineage, member_handle]
    entities.append(
        {
            "handle": insert_handle,
            "type": "insert",
            "layer": str(insert.dxf.layer),
            "block": str(insert.dxf.name),
            "transform": matrix_payload(insert.matrix44()),
            "source_lineage": own_lineage,
            "layout": layout,
            "owner": lineage[-1] if lineage else layout,
            "source_member_handle": member_handle,
        }
    )
    try:
        virtual_entities = list(insert.virtual_entities())
        source_entities = list(insert.block())
    except Exception as exception:
        raise SafeFailure("cad_required_entity_incomplete") from exception
    if not virtual_entities or len(virtual_entities) != len(source_entities):
        raise SafeFailure("cad_required_entity_incomplete")
    for member_index, (virtual, source_entity) in enumerate(
        zip(virtual_entities, source_entities, strict=True)
    ):
        source_handle = str(
            source_entity.dxf.handle or f"{insert.dxf.name}:member:{member_index}"
        )
        if virtual.dxftype() == "INSERT":
            expand_insert(
                virtual,
                own_lineage,
                depth + 1,
                max_depth,
                entities,
                texts,
                dimensions,
                layout,
                source_handle,
            )
            continue
        mapped, text, dimension = map_dxf_entity(
            virtual, own_lineage, str(insert.dxf.name), layout, source_handle
        )
        if mapped is not None:
            entities.append(mapped)
        if text is not None:
            texts.append(text)
        if dimension is not None:
            dimensions.append(dimension)


def parse_dxf(path: str, max_depth: int) -> tuple[Any, ...]:
    import ezdxf

    try:
        document = ezdxf.readfile(path)
    except Exception as exception:
        raise SafeFailure("dxf_decode_failed") from exception
    unit, status = source_unit(document.header.get("$INSUNITS", 0))
    layers = [
        {"name": layer.dxf.name, "visible": not layer.is_off()}
        for layer in document.layers
    ]
    blocks = []
    for block in document.blocks:
        if block.name.startswith("*"):
            continue
        blocks.append(
            {
                "name": block.name,
                "handle": str(block.block_record_handle),
                "owner": "blocks",
                "entities": [
                    str(entity.dxf.handle or entity.dxftype()) for entity in block
                ],
            }
        )
    entities: list[dict[str, Any]] = []
    texts: list[dict[str, Any]] = []
    dimensions: list[dict[str, Any]] = []
    for layout in document.layouts:
        for entity in layout:
            if entity.dxftype() not in SUPPORTED:
                raise SafeFailure("cad_unsupported_entities")
            if entity.dxftype() == "INSERT":
                expand_insert(
                    entity, [], 1, max_depth, entities, texts, dimensions, layout.name
                )
                continue
            mapped, text, dimension = map_dxf_entity(entity, [], None, layout.name)
            if mapped is not None:
                entities.append(mapped)
            if text is not None:
                texts.append(text)
            if dimension is not None:
                dimensions.append(dimension)
    if not entities and not texts and not dimensions:
        raise SafeFailure("cad_geometry_empty")
    return unit, status, layers, blocks, entities, texts, dimensions, [], "ezdxf:1.4.4"


def checked_libredwg_version(binary: str, workspace: str) -> None:
    stdout_path = os.path.join(workspace, "libredwg-version.stdout")
    stderr_path = os.path.join(workspace, "libredwg-version.stderr")
    try:
        with open(stdout_path, "wb") as stdout_stream, open(
            stderr_path, "wb"
        ) as stderr_stream:
            process = subprocess.run(
                [binary, "--version"],
                cwd=workspace,
                stdout=stdout_stream,
                stderr=stderr_stream,
                timeout=5,
                check=False,
            )
    except (OSError, subprocess.TimeoutExpired) as exception:
        raise SafeFailure("libredwg_unavailable", True) from exception
    if os.path.getsize(stdout_path) > 4096 or os.path.getsize(stderr_path) > 4096:
        raise SafeFailure("libredwg_version_output_oversize")
    output = Path(stdout_path).read_text(encoding="utf-8", errors="replace") + Path(
        stderr_path
    ).read_text(encoding="utf-8", errors="replace")
    if process.returncode != 0 or re.search(r"\b0\.13\.4\b", output) is None:
        raise SafeFailure("libredwg_version_mismatch")


def diagnostic_counts(diagnostic: str) -> dict[str, int]:
    counts: dict[str, int] = {}
    for category in ("unsupported", "skipped", "unknown"):
        explicit = [
            int(value)
            for value in re.findall(
                rf"{category}(?:\s+entities?)?\s*[:=]\s*(\d+)",
                diagnostic,
                flags=re.IGNORECASE,
            )
        ]
        if explicit:
            counts[category] = sum(explicit)
            continue
        occurrences = len(re.findall(rf"\b{category}\b", diagnostic, re.IGNORECASE))
        if occurrences:
            counts[category] = occurrences
    return counts


def parse_dwg(
    path: str, binary: str, workspace: str, max_output_bytes: int
) -> tuple[Any, ...]:
    checked_libredwg_version(binary, workspace)
    json_path = os.path.join(workspace, "libredwg.json")
    error_path = os.path.join(workspace, "libredwg.stderr")
    try:
        with open(json_path, "wb") as output_stream, open(
            error_path, "wb"
        ) as error_stream:
            process = subprocess.run(
                [binary, "-O", "JSON", path],
                cwd=workspace,
                stdout=output_stream,
                stderr=error_stream,
                timeout=30,
                check=False,
            )
    except (OSError, subprocess.TimeoutExpired) as exception:
        raise SafeFailure("libredwg_unavailable", True) from exception
    if (
        os.path.getsize(json_path) > max_output_bytes
        or os.path.getsize(error_path) > 8192
    ):
        raise SafeFailure("cad_output_oversize")
    diagnostic = Path(error_path).read_text(encoding="utf-8", errors="replace")
    if process.returncode != 0:
        raise SafeFailure("dwg_decode_failed")
    counts = diagnostic_counts(diagnostic)
    try:
        data = json.loads(Path(json_path).read_text(encoding="utf-8"))
    except Exception as exception:
        raise SafeFailure("dwg_contract_invalid") from exception
    object_records = data.get("OBJECTS", [])
    if not isinstance(object_records, list):
        raise SafeFailure("dwg_contract_invalid")
    raw_entity_records = sum(
        1 for item in object_records if isinstance(item, dict) and item.get("entity")
    )
    if counts or re.search(r"\b(?:error|warning)\b", diagnostic, re.IGNORECASE):
        raise SafeFailure(
            "dwg_completeness_unproven",
            context={
                "decoder_counts": counts,
                "reconciliation": {
                    "object_records": len(object_records),
                    "entity_records": raw_entity_records,
                },
            },
        )
    if data.get("created_by") != "LibreDWG 0.13.4":
        raise SafeFailure("libredwg_provenance_invalid")
    layers = []
    entities = []
    texts = []
    dimensions = []
    entity_records = 0
    for item in object_records:
        if item.get("object") == "LAYER":
            layers.append(
                {
                    "name": str(item.get("name", "0")),
                    "visible": not bool(item.get("flag", 0) & 1),
                }
            )
        entity_type = item.get("entity")
        if not entity_type:
            continue
        entity_records += 1
        if entity_type not in SUPPORTED:
            raise SafeFailure("cad_unsupported_entities")
        item_handle = handle(item.get("handle"))
        layer = handle(item.get("layer"))
        base = {
            "handle": item_handle,
            "type": entity_type.lower(),
            "layer": layer,
            "layout": "model",
            "owner": "model",
            "source_lineage": [item_handle],
        }
        if entity_type == "LINE" and "start" in item and "end" in item:
            base["points"] = [point(item["start"]), point(item["end"])]
            entities.append(base)
        elif entity_type == "LWPOLYLINE" and len(item.get("points", [])) >= 2:
            base["points"] = [point(value) for value in item["points"]]
            base["closed"] = bool(item.get("flag", 0) & 512)
            entities.append(base)
        elif entity_type in {"ARC", "CIRCLE"} and "center" in item and "radius" in item:
            base["center"] = point(item["center"])
            base["radius"] = number(item["radius"])
            if entity_type == "ARC" and "start_angle" in item and "end_angle" in item:
                base["start_angle"] = number(item["start_angle"])
                base["end_angle"] = number(item["end_angle"])
            elif entity_type == "ARC":
                raise SafeFailure("cad_required_entity_incomplete")
            entities.append(base)
        elif entity_type in {"TEXT", "MTEXT"} and "ins_pt" in item:
            texts.append(
                {
                    "handle": item_handle,
                    "type": entity_type.lower(),
                    "layer": layer,
                    "text": str(item.get("text_value", "")),
                    "position": point(item["ins_pt"]),
                    "layout": "model",
                    "owner": "model",
                }
            )
        else:
            raise SafeFailure("cad_required_entity_incomplete")
    if not entities and not texts and not dimensions:
        raise SafeFailure("dwg_geometry_empty")
    represented_records = len(entities) + len(texts) + len(dimensions)
    if represented_records != entity_records:
        raise SafeFailure(
            "dwg_reconciliation_failed",
            context={
                "object_records": len(data.get("OBJECTS", [])),
                "entity_records": entity_records,
                "represented_records": represented_records,
            },
        )
    measurement = data.get("Template", {}).get("MEASUREMENT", 0)
    unit, status = ("mm", "confirmed") if measurement == 1 else (None, "unknown")
    return unit, status, layers, [], entities, texts, dimensions, [], "libredwg:0.13.4"


def geometry_bounds(entities: list[dict[str, Any]]) -> list[float]:
    points = []
    for entity in entities:
        points.extend(entity.get("points", []))
        if "center" in entity:
            points.append(entity["center"])
    if not points:
        return []
    return [
        min(value[0] for value in points),
        min(value[1] for value in points),
        max(value[0] for value in points),
        max(value[1] for value in points),
    ]


def parser() -> argparse.ArgumentParser:
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--input", required=True)
    argument_parser.add_argument("--workspace", required=True)
    argument_parser.add_argument("--dwgread", default="dwgread")
    argument_parser.add_argument("--max-output-bytes", type=int, default=16_777_216)
    argument_parser.add_argument("--max-block-depth", type=int, default=8)
    return argument_parser


def main() -> int:
    args = parser().parse_args()
    source = os.path.realpath(args.input)
    workspace = os.path.realpath(args.workspace)
    if os.path.commonpath([source, workspace]) != workspace or not os.path.isfile(
        source
    ):
        raise SafeFailure("cad_path_invalid")
    fingerprint = sha256_file(source)
    parsed = (
        parse_dwg(source, args.dwgread, workspace, args.max_output_bytes)
        if source.lower().endswith(".dwg")
        else parse_dxf(source, args.max_block_depth)
    )
    unit, status, layers, blocks, entities, texts, dimensions, warnings, runtime = (
        parsed
    )
    result = {
        "schema_version": 1,
        "runtime_version": "cad-geometry:v1;" + runtime,
        "source_fingerprint": fingerprint,
        "source_unit": unit,
        "unit_status": status,
        "bounds": geometry_bounds(entities),
        "layers": layers,
        "blocks": blocks,
        "entities": entities,
        "texts": texts,
        "dimensions": dimensions,
        "pages": [],
        "scale_candidates": [],
        "warnings": warnings,
    }
    output = json.dumps(
        result, separators=(",", ":"), ensure_ascii=False, allow_nan=False
    )
    if len(output.encode("utf-8")) > args.max_output_bytes:
        raise SafeFailure("cad_output_oversize")
    sys.stdout.write(output)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SafeFailure as exception:
        sys.stderr.write(
            json.dumps(
                {
                    "code": exception.code,
                    "safe_message": "Не удалось безопасно обработать чертёж.",
                    "retryable": exception.retryable,
                    "context": exception.context,
                },
                ensure_ascii=False,
            )
        )
        raise SystemExit(2)
    except Exception:
        sys.stderr.write(
            json.dumps(
                {
                    "code": "cad_parse_failed",
                    "safe_message": "Не удалось безопасно обработать чертёж.",
                    "retryable": False,
                    "context": {},
                },
                ensure_ascii=False,
            )
        )
        raise SystemExit(2)
