#!/usr/bin/env python3
from __future__ import annotations

import argparse
import ctypes
import hashlib
import json
import math
import os
import sys
from pathlib import Path
from typing import Any

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")


class SafeFailure(Exception):
    def __init__(self, code: str):
        super().__init__(code)
        self.code = code


def sha256_file(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return "sha256:" + digest.hexdigest()


def finite(value: float) -> float:
    value = round(float(value), 6)
    if not math.isfinite(value):
        raise SafeFailure("pdf_number_invalid")
    return value


def transform_point(matrix: list[float], point: list[float]) -> list[float]:
    a, b, c, d, e, f = matrix
    x, y = point
    return [finite(a * x + c * y + e), finite(b * x + d * y + f)]


def compose(parent: list[float], child: list[float]) -> list[float]:
    a1, b1, c1, d1, e1, f1 = child
    a2, b2, c2, d2, e2, f2 = parent
    return [
        finite(a2 * a1 + c2 * b1),
        finite(b2 * a1 + d2 * b1),
        finite(a2 * c1 + c2 * d1),
        finite(b2 * c1 + d2 * d1),
        finite(a2 * e1 + c2 * f1 + e2),
        finite(b2 * e1 + d2 * f1 + f2),
    ]


def effective_object_transform(
    obj: Any, max_depth: int
) -> tuple[list[float], list[list[float]]]:
    matrices: list[list[float]] = []
    current = obj
    seen: set[int] = set()
    while hasattr(current, "get_matrix"):
        identity = id(current)
        if identity in seen:
            raise SafeFailure("pdf_form_cycle_detected")
        seen.add(identity)
        matrices.append([finite(value) for value in current.get_matrix().get()])
        if len(matrices) > max_depth:
            raise SafeFailure("pdf_form_depth_exceeded")
        current = getattr(current, "container", None)
        if current is None:
            break
    effective = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]
    for matrix in reversed(matrices):
        effective = compose(effective, matrix)
    return effective, matrices


def page_transform(box: list[float], rotation: int) -> list[float]:
    left, bottom, right, top = box
    transforms = {
        0: [1.0, 0.0, 0.0, 1.0, -left, -bottom],
        90: [0.0, -1.0, 1.0, 0.0, -bottom, right],
        180: [-1.0, 0.0, 0.0, -1.0, right, top],
        270: [0.0, 1.0, -1.0, 0.0, top, -left],
    }
    if rotation not in transforms:
        raise SafeFailure("pdf_rotation_invalid")
    return [finite(value) for value in transforms[rotation]]


def safe_box(getter: Any, fallback: list[float]) -> list[float]:
    try:
        box = [finite(value) for value in getter(fallback_ok=False)]
    except Exception:
        box = fallback.copy()
    if len(box) != 4 or box[0] >= box[2] or box[1] >= box[3]:
        raise SafeFailure("pdf_page_box_invalid")
    return box


def rgba(raw: Any, function: Any) -> list[int] | None:
    values = [ctypes.c_uint() for _ in range(4)]
    if not function(raw, *(ctypes.byref(value) for value in values)):
        return None
    return [int(value.value) for value in values]


def path_style(raw: Any, pdfium_raw: Any) -> dict[str, Any]:
    fill_mode = ctypes.c_int()
    stroke = ctypes.c_int()
    if not pdfium_raw.FPDFPath_GetDrawMode(
        raw, ctypes.byref(fill_mode), ctypes.byref(stroke)
    ):
        raise SafeFailure("pdf_path_style_invalid")
    width = ctypes.c_float()
    if not pdfium_raw.FPDFPageObj_GetStrokeWidth(raw, ctypes.byref(width)):
        raise SafeFailure("pdf_path_style_invalid")
    return {
        "fill_mode": int(fill_mode.value),
        "stroke": bool(stroke.value),
        "fill_rgba": rgba(raw, pdfium_raw.FPDFPageObj_GetFillColor),
        "stroke_rgba": rgba(raw, pdfium_raw.FPDFPageObj_GetStrokeColor),
        "stroke_width": finite(width.value),
        "line_cap": int(pdfium_raw.FPDFPageObj_GetLineCap(raw)),
        "line_join": int(pdfium_raw.FPDFPageObj_GetLineJoin(raw)),
    }


def path_segments(
    raw: Any, matrix: list[float], pdfium_raw: Any, budget: dict[str, int]
) -> tuple[list[dict[str, Any]], list[list[float]]]:
    count = int(pdfium_raw.FPDFPath_CountSegments(raw))
    if count < 1:
        raise SafeFailure("pdf_path_empty")
    budget["segments"] += count
    if budget["segments"] > budget["max_segments"]:
        raise SafeFailure("pdf_segment_limit_exceeded")
    primitives: list[dict[str, Any]] = []
    flattened: list[list[float]] = []
    index = 0
    current: list[float] | None = None
    while index < count:
        segment = pdfium_raw.FPDFPath_GetPathSegment(raw, index)
        if not segment:
            raise SafeFailure("pdf_path_segment_invalid")
        x_value = ctypes.c_float()
        y_value = ctypes.c_float()
        if not pdfium_raw.FPDFPathSegment_GetPoint(
            segment, ctypes.byref(x_value), ctypes.byref(y_value)
        ):
            raise SafeFailure("pdf_path_segment_invalid")
        point = transform_point(matrix, [finite(x_value.value), finite(y_value.value)])
        segment_type = int(pdfium_raw.FPDFPathSegment_GetType(segment))
        closes = bool(pdfium_raw.FPDFPathSegment_GetClose(segment))
        if segment_type == pdfium_raw.FPDF_SEGMENT_MOVETO:
            primitives.append(
                {
                    "operator": "move",
                    "points": [point],
                    "source_indices": [index],
                    "closes_subpath": closes,
                }
            )
            current = point
            flattened.append(point)
            index += 1
            continue
        if segment_type == pdfium_raw.FPDF_SEGMENT_LINETO and current is not None:
            primitives.append(
                {
                    "operator": "line",
                    "points": [current, point],
                    "source_indices": [index],
                    "closes_subpath": closes,
                }
            )
            current = point
            flattened.append(point)
            index += 1
            continue
        if (
            segment_type == pdfium_raw.FPDF_SEGMENT_BEZIERTO
            and current is not None
            and index + 2 < count
        ):
            controls: list[list[float]] = []
            close_curve = closes
            for offset in range(3):
                curve_segment = pdfium_raw.FPDFPath_GetPathSegment(raw, index + offset)
                if (
                    not curve_segment
                    or int(pdfium_raw.FPDFPathSegment_GetType(curve_segment))
                    != pdfium_raw.FPDF_SEGMENT_BEZIERTO
                ):
                    raise SafeFailure("pdf_bezier_invalid")
                curve_x = ctypes.c_float()
                curve_y = ctypes.c_float()
                if not pdfium_raw.FPDFPathSegment_GetPoint(
                    curve_segment, ctypes.byref(curve_x), ctypes.byref(curve_y)
                ):
                    raise SafeFailure("pdf_bezier_invalid")
                controls.append(transform_point(matrix, [curve_x.value, curve_y.value]))
                close_curve = close_curve or bool(
                    pdfium_raw.FPDFPathSegment_GetClose(curve_segment)
                )
            primitives.append(
                {
                    "operator": "curve",
                    "points": [current, *controls],
                    "source_indices": [index, index + 1, index + 2],
                    "closes_subpath": close_curve,
                }
            )
            current = controls[-1]
            flattened.extend(controls)
            index += 3
            continue
        raise SafeFailure("pdf_path_operator_unsupported")
    return primitives, flattened


def bbox(points: list[list[float]]) -> dict[str, float] | None:
    if not points:
        return None
    xs = [point[0] for point in points]
    ys = [point[1] for point in points]
    return {
        "x": min(xs),
        "y": min(ys),
        "width": finite(max(xs) - min(xs)),
        "height": finite(max(ys) - min(ys)),
    }


def extract(args: argparse.Namespace) -> dict[str, Any]:
    try:
        import pypdfium2 as pdfium
        from pypdfium2 import raw
    except Exception as exception:
        raise SafeFailure("pypdfium2_unavailable") from exception
    try:
        document = pdfium.PdfDocument(args.input)
    except Exception as exception:
        raise SafeFailure("pdf_invalid") from exception
    if len(document) < 1 or len(document) > args.max_pages:
        raise SafeFailure("pdf_page_limit_exceeded")
    fingerprint = sha256_file(args.input)
    pages: list[dict[str, Any]] = []
    entities: list[dict[str, Any]] = []
    texts: list[dict[str, Any]] = []
    budget = {
        "objects": 0,
        "segments": 0,
        "text_chars": 0,
        "max_segments": args.max_segments,
    }
    warnings: list[str] = []
    for page_index in range(len(document)):
        page = document[page_index]
        media = safe_box(page.get_mediabox, [0.0, 0.0, *page.get_size()])
        crop = safe_box(page.get_cropbox, media)
        rotation = int(page.get_rotation()) % 360
        normalization = page_transform(crop, rotation)
        width = crop[2] - crop[0]
        height = crop[3] - crop[1]
        displayed_width, displayed_height = (
            (height, width) if rotation in (90, 270) else (width, height)
        )
        text_page = page.get_textpage()
        page_path_count = 0
        page_image_count = 0
        for object_index, obj in enumerate(
            page.get_objects(max_depth=args.max_form_depth, textpage=text_page)
        ):
            budget["objects"] += 1
            if budget["objects"] > args.max_objects:
                if "pdf_vector_object_limit_reached" not in warnings:
                    warnings.append("pdf_vector_object_limit_reached")
                break
            handle = f"page:{page_index + 1}:object:{object_index}"
            object_matrix, transform_lineage = effective_object_transform(
                obj, args.max_form_depth
            )
            effective = compose(normalization, object_matrix)
            if obj.type == raw.FPDF_PAGEOBJ_PATH:
                segments, points = path_segments(obj, effective, raw, budget)
                entities.append(
                    {
                        "handle": handle,
                        "type": "path",
                        "layer": "page",
                        "points": points,
                        "segments": segments,
                        "transform": effective,
                        "transform_lineage": transform_lineage,
                        "source_lineage": [f"page:{page_index + 1}", handle],
                        "layout": f"page:{page_index + 1}",
                        "owner": f"page:{page_index + 1}",
                        "bbox": bbox(points),
                        "style": path_style(obj, raw),
                    }
                )
                page_path_count += 1
            elif obj.type == raw.FPDF_PAGEOBJ_TEXT:
                value = obj.extract()
                budget["text_chars"] += len(value)
                if budget["text_chars"] > args.max_text_chars:
                    raise SafeFailure("pdf_text_limit_exceeded")
                raw_bounds = [finite(value) for value in obj.get_bounds()]
                corners = [
                    transform_point(normalization, raw_bounds[:2]),
                    transform_point(normalization, raw_bounds[2:]),
                ]
                texts.append(
                    {
                        "handle": handle,
                        "type": "text",
                        "layer": "page",
                        "text": value,
                        "position": corners[0],
                        "layout": f"page:{page_index + 1}",
                        "source_operator": handle,
                        "transform": effective,
                        "owner": f"page:{page_index + 1}",
                        "bbox": bbox(corners),
                    }
                )
            elif obj.type == raw.FPDF_PAGEOBJ_IMAGE:
                page_image_count += 1
        if page_path_count and page_image_count:
            classification = "mixed"
        elif page_path_count:
            classification = "vector"
        elif page_image_count:
            classification = "raster"
        else:
            classification = "empty"
        pages.append(
            {
                "page_number": page_index + 1,
                "width": finite(displayed_width),
                "height": finite(displayed_height),
                "rotation": rotation,
                "media_box": media,
                "crop_box": crop,
                "transform": normalization,
                "classification": classification,
            }
        )
    all_points = [point for entity in entities for point in entity["points"]]
    geometry_bbox = bbox(all_points)
    bounds = (
        []
        if geometry_bbox is None
        else [
            geometry_bbox["x"],
            geometry_bbox["y"],
            geometry_bbox["x"] + geometry_bbox["width"],
            geometry_bbox["y"] + geometry_bbox["height"],
        ]
    )
    return {
        "schema_version": 1,
        "runtime_version": "pdf-geometry:v1;pypdfium2:5.8.0",
        "source_fingerprint": fingerprint,
        "source_unit": None,
        "unit_status": "unknown",
        "bounds": bounds,
        "layers": [{"name": "page", "visible": True}],
        "blocks": [],
        "entities": entities,
        "texts": texts,
        "dimensions": [],
        "pages": pages,
        "scale_candidates": [],
        "warnings": warnings,
    }


def raster_contract(args: argparse.Namespace) -> dict[str, Any]:
    try:
        import pypdfium2 as pdfium
    except Exception as exception:
        raise SafeFailure("pypdfium2_unavailable") from exception
    try:
        document = pdfium.PdfDocument(args.input)
    except Exception as exception:
        raise SafeFailure("pdf_invalid") from exception
    if len(document) < 1 or len(document) > args.max_pages:
        raise SafeFailure("pdf_page_limit_exceeded")
    pages: list[dict[str, Any]] = []
    for page_index in range(len(document)):
        page = document[page_index]
        media = safe_box(page.get_mediabox, [0.0, 0.0, *page.get_size()])
        crop = safe_box(page.get_cropbox, media)
        rotation = int(page.get_rotation()) % 360
        width = crop[2] - crop[0]
        height = crop[3] - crop[1]
        displayed_width, displayed_height = (
            (height, width) if rotation in (90, 270) else (width, height)
        )
        pages.append(
            {
                "page_number": page_index + 1,
                "width": finite(displayed_width),
                "height": finite(displayed_height),
                "rotation": rotation,
                "media_box": media,
                "crop_box": crop,
                "transform": page_transform(crop, rotation),
                "classification": "raster",
            }
        )
    return {
        "schema_version": 1,
        "runtime_version": "pdf-geometry:v1;pypdfium2:5.8.0",
        "source_fingerprint": sha256_file(args.input),
        "source_unit": None,
        "unit_status": "unknown",
        "bounds": [],
        "layers": [{"name": "page", "visible": True}],
        "blocks": [],
        "entities": [],
        "texts": [],
        "dimensions": [],
        "pages": pages,
        "scale_candidates": [],
        "warnings": ["pdf_vector_geometry_unavailable"],
    }


def extract_with_raster_fallback(args: argparse.Namespace) -> dict[str, Any]:
    try:
        return extract(args)
    except Exception:
        if args.contract_vector:
            raise
        return raster_contract(args)


def legacy(contract: dict[str, Any], args: argparse.Namespace) -> dict[str, Any]:
    pages: list[dict[str, Any]] = []
    preview_total_bytes = 0
    preview_total_pixels = 0
    for page in contract["pages"]:
        page_number = page["page_number"]
        page_entities = [
            entity
            for entity in contract["entities"]
            if entity["layout"] == f"page:{page_number}"
        ]
        page_texts = [
            text
            for text in contract["texts"]
            if text["layout"] == f"page:{page_number}"
        ]
        vector_elements: list[dict[str, Any]] = []
        line_count = 0
        curve_count = 0
        for drawing_index, entity in enumerate(page_entities):
            for item_index, segment in enumerate(entity["segments"]):
                if segment["operator"] == "move":
                    continue
                kind = "line" if segment["operator"] == "line" else "curve"
                line_count += int(kind == "line")
                curve_count += int(kind == "curve")
                vector_elements.append(
                    {
                        "kind": kind,
                        "bbox": bbox(segment["points"]),
                        "geometry": {"points": segment["points"]},
                        "style": {
                            "drawing_index": drawing_index,
                            "item_index": item_index,
                            "stroke_width": entity["style"]["stroke_width"],
                            "path_bbox": entity["bbox"],
                            "source_operator": entity["handle"],
                        },
                    }
                )
        preview = {"path": None}
        if args.render_preview and args.preview_dir:
            import pypdfium2 as pdfium

            if not args.workspace:
                raise SafeFailure("pdf_preview_workspace_required")
            workspace = os.path.realpath(args.workspace)
            preview_directory = os.path.realpath(args.preview_dir)
            if os.path.commonpath([workspace, preview_directory]) != workspace:
                raise SafeFailure("pdf_preview_path_invalid")
            safe_filename = "".join(
                character if character.isalnum() or character in {"-", "_"} else "_"
                for character in (args.filename or os.path.basename(args.input))
            )[:80]
            output = Path(preview_directory) / f"{safe_filename}_page_{page_number}.png"
            output.parent.mkdir(parents=True, exist_ok=True)
            expected_width = max(1, math.ceil(float(page["width"])))
            expected_height = max(1, math.ceil(float(page["height"])))
            expected_pixels = expected_width * expected_height
            if expected_pixels > args.max_preview_page_pixels:
                raise SafeFailure("pdf_preview_invalid")
            if preview_total_pixels + expected_pixels > args.max_preview_total_pixels:
                raise SafeFailure("pdf_preview_aggregate_pixels_limit")
            document = pdfium.PdfDocument(args.input)
            bitmap = document[page_number - 1].render(scale=1)
            image = bitmap.to_pil()
            image.save(output)
            output_bytes = output.stat().st_size
            if output_bytes < 1 or output_bytes > args.max_preview_page_bytes:
                output.unlink(missing_ok=True)
                raise SafeFailure("pdf_preview_invalid")
            if preview_total_bytes + output_bytes > args.max_preview_total_bytes:
                output.unlink(missing_ok=True)
                raise SafeFailure("pdf_preview_aggregate_bytes_limit")
            preview_total_pixels += image.width * image.height
            preview_total_bytes += output_bytes
            preview = {
                "path": str(output),
                "width": image.width,
                "height": image.height,
            }
        pages.append(
            {
                "page_number": page_number,
                "width": page["width"],
                "height": page["height"],
                "rotation": page["rotation"],
                "text_blocks": [
                    {
                        "text": text["text"],
                        "bbox": text["bbox"],
                        "block_no": index,
                        "block_type": 0,
                    }
                    for index, text in enumerate(page_texts)
                ],
                "vector_elements": vector_elements,
                "visual_metrics": {
                    "path_count": len(page_entities),
                    "line_count": line_count,
                    "curve_count": curve_count,
                    "rect_count": 0,
                    "vector_element_count": len(vector_elements),
                    "stored_vector_element_count": len(vector_elements),
                    "text_block_count": len(page_texts),
                    "table_candidate_count": 0,
                    "contour_candidate_count": max(0, line_count // 4),
                    "title_block_candidate_count": 0,
                    "geometry_density": round(
                        len(vector_elements) / max(page["width"] * page["height"], 1.0),
                        8,
                    ),
                },
                "page_role": "geometry_only" if vector_elements else "empty",
                "signals": (
                    ["vector_geometry", "plan_candidate"]
                    if vector_elements
                    else ["text_empty"]
                ),
                "preview": preview,
            }
        )
    return {
        "provider": "pymupdf",
        "model": "geometry_v1",
        "pages": pages,
        "metadata": {
            "page_count": len(contract["pages"]),
            "processed_page_count": len(pages),
            "filename": args.filename or os.path.basename(args.input),
            "pypdfium2_version": "5.8.0",
            "actual_provider": "pypdfium2",
            "actual_runtime_version": "5.8.0",
            "warnings": contract.get("warnings", []),
        },
    }


def parser() -> argparse.ArgumentParser:
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--input", required=True)
    argument_parser.add_argument("--workspace", default="")
    argument_parser.add_argument("--contract-vector", action="store_true")
    argument_parser.add_argument("--filename", default="")
    argument_parser.add_argument("--preview-dir", default="")
    argument_parser.add_argument("--max-pages", type=int, default=200)
    argument_parser.add_argument(
        "--max-vector-elements", dest="max_objects", type=int, default=5000
    )
    argument_parser.add_argument("--max-segments", type=int, default=100000)
    argument_parser.add_argument("--max-text-chars", type=int, default=1000000)
    argument_parser.add_argument("--max-form-depth", type=int, default=8)
    argument_parser.add_argument("--render-preview", action="store_true")
    argument_parser.add_argument("--max-preview-page-bytes", type=int, default=20000000)
    argument_parser.add_argument("--max-preview-page-pixels", type=int, default=25000000)
    argument_parser.add_argument("--max-preview-total-bytes", type=int, default=100000000)
    argument_parser.add_argument("--max-preview-total-pixels", type=int, default=100000000)
    return argument_parser


def main() -> int:
    args = parser().parse_args()
    real_input = os.path.realpath(args.input)
    if args.workspace and os.path.commonpath(
        [real_input, os.path.realpath(args.workspace)]
    ) != os.path.realpath(args.workspace):
        raise SafeFailure("pdf_path_invalid")
    args.input = real_input
    contract = extract_with_raster_fallback(args)
    payload = contract if args.contract_vector else legacy(contract, args)
    sys.stdout.write(
        json.dumps(payload, separators=(",", ":"), ensure_ascii=False, allow_nan=False)
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except SafeFailure as exception:
        if "--contract-vector" not in sys.argv:
            error = (
                "pymupdf_unavailable"
                if exception.code == "pypdfium2_unavailable"
                else exception.code
            )
            sys.stderr.write(
                json.dumps(
                    {"error": error, "message": "PDF geometry extraction failed."},
                    ensure_ascii=False,
                )
            )
        else:
            sys.stderr.write(
                json.dumps(
                    {
                        "code": exception.code,
                        "safe_message": "Не удалось безопасно обработать документ.",
                        "retryable": False,
                    },
                    ensure_ascii=False,
                )
            )
        raise SystemExit(2)
    except Exception:
        if "--contract-vector" not in sys.argv:
            sys.stderr.write(
                json.dumps(
                    {
                        "error": "pdf_geometry_extract_failed",
                        "message": "PDF geometry extraction failed.",
                    }
                )
            )
        else:
            sys.stderr.write(
                json.dumps(
                    {
                        "code": "pdf_parse_failed",
                        "safe_message": "Не удалось безопасно обработать документ.",
                        "retryable": False,
                    },
                    ensure_ascii=False,
                )
            )
        raise SystemExit(2)
