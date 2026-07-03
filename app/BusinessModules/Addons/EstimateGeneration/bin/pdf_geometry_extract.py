#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import os
import sys
from pathlib import Path
from typing import Any

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8")

try:
    import fitz
except Exception as exc:  # pragma: no cover
    print(json.dumps({"error": "pymupdf_unavailable", "message": str(exc)}), file=sys.stderr)
    sys.exit(77)


def point_payload(point: Any) -> list[float]:
    return [round(float(point.x), 4), round(float(point.y), 4)]


def rect_payload(rect: Any) -> list[float]:
    return [
        round(float(rect.x0), 4),
        round(float(rect.y0), 4),
        round(float(rect.x1), 4),
        round(float(rect.y1), 4),
    ]


def bbox_from_points(points: list[list[float]]) -> dict[str, float] | None:
    if not points:
        return None

    xs = [point[0] for point in points]
    ys = [point[1] for point in points]
    left = min(xs)
    top = min(ys)
    right = max(xs)
    bottom = max(ys)

    return {
        "x": round(left, 4),
        "y": round(top, 4),
        "width": round(max(right - left, 0.0), 4),
        "height": round(max(bottom - top, 0.0), 4),
    }


def bbox_from_rect(rect: Any) -> dict[str, float]:
    return {
        "x": round(float(rect.x0), 4),
        "y": round(float(rect.y0), 4),
        "width": round(max(float(rect.x1) - float(rect.x0), 0.0), 4),
        "height": round(max(float(rect.y1) - float(rect.y0), 0.0), 4),
    }


def normalize_text_blocks(page: Any) -> list[dict[str, Any]]:
    blocks: list[dict[str, Any]] = []

    for block in page.get_text("blocks", sort=True):
        if len(block) < 7:
            continue

        x0, y0, x1, y1, text, block_no, block_type = block[:7]
        text_value = str(text or "").strip()
        blocks.append(
            {
                "text": text_value,
                "bbox": {
                    "x": round(float(x0), 4),
                    "y": round(float(y0), 4),
                    "width": round(max(float(x1) - float(x0), 0.0), 4),
                    "height": round(max(float(y1) - float(y0), 0.0), 4),
                },
                "block_no": int(block_no),
                "block_type": int(block_type),
            }
        )

    return blocks


def drawing_elements(page: Any, max_elements: int) -> tuple[list[dict[str, Any]], dict[str, int]]:
    elements: list[dict[str, Any]] = []
    metrics = {
        "path_count": 0,
        "line_count": 0,
        "curve_count": 0,
        "rect_count": 0,
    }

    for drawing_index, drawing in enumerate(page.get_drawings()):
        metrics["path_count"] += 1
        items = drawing.get("items", [])
        path_rect = drawing.get("rect")
        path_bbox = bbox_from_rect(path_rect) if path_rect is not None else None

        for item_index, item in enumerate(items):
            if not item:
                continue

            command = str(item[0])
            payload: dict[str, Any] = {
                "drawing_index": drawing_index,
                "item_index": item_index,
                "stroke_width": round(float(drawing.get("width") or 0.0), 4),
                "path_bbox": path_bbox,
            }

            if command == "l" and len(item) >= 3:
                points = [point_payload(item[1]), point_payload(item[2])]
                metrics["line_count"] += 1
                if len(elements) < max_elements:
                    elements.append(
                        {
                            "kind": "line",
                            "bbox": bbox_from_points(points),
                            "geometry": {"points": points},
                            "style": payload,
                        }
                    )
                continue

            if command == "re" and len(item) >= 2:
                rect = item[1]
                metrics["rect_count"] += 1
                if len(elements) < max_elements:
                    elements.append(
                        {
                            "kind": "rect",
                            "bbox": bbox_from_rect(rect),
                            "geometry": {"rect": rect_payload(rect)},
                            "style": payload,
                        }
                    )
                continue

            if command == "c" and len(item) >= 5:
                points = [point_payload(item[1]), point_payload(item[2]), point_payload(item[3]), point_payload(item[4])]
                metrics["curve_count"] += 1
                if len(elements) < max_elements:
                    elements.append(
                        {
                            "kind": "curve",
                            "bbox": bbox_from_points(points),
                            "geometry": {"points": points},
                            "style": payload,
                        }
                    )
                continue

            points = []
            for value in item[1:]:
                if hasattr(value, "x") and hasattr(value, "y"):
                    points.append(point_payload(value))
                elif hasattr(value, "rect"):
                    rect = value.rect
                    points.extend(
                        [
                            [float(rect.x0), float(rect.y0)],
                            [float(rect.x1), float(rect.y1)],
                        ]
                    )

            if len(elements) < max_elements:
                elements.append(
                    {
                        "kind": command,
                        "bbox": bbox_from_points(points) or path_bbox,
                        "geometry": {"points": points},
                        "style": payload,
                    }
                )

    return elements, metrics


def line_orientation(element: dict[str, Any]) -> str | None:
    if element.get("kind") != "line":
        return None

    points = element.get("geometry", {}).get("points", [])
    if len(points) < 2:
        return None

    dx = abs(float(points[1][0]) - float(points[0][0]))
    dy = abs(float(points[1][1]) - float(points[0][1]))

    if dx < 0.5 and dy >= 4.0:
        return "vertical"

    if dy < 0.5 and dx >= 4.0:
        return "horizontal"

    return None


def overlap(a0: float, a1: float, b0: float, b1: float) -> float:
    return max(min(a1, b1) - max(a0, b0), 0.0)


def table_candidate_count(elements: list[dict[str, Any]]) -> int:
    horizontal = []
    vertical = []

    for element in elements:
        bbox = element.get("bbox") if isinstance(element.get("bbox"), dict) else None
        orientation = line_orientation(element)

        if bbox is None:
            continue

        if orientation == "horizontal":
            horizontal.append(bbox)
        elif orientation == "vertical":
            vertical.append(bbox)
        elif element.get("kind") == "rect":
            if float(bbox.get("width", 0)) > 20 and float(bbox.get("height", 0)) > 8:
                horizontal.append(bbox)
                vertical.append(bbox)

    candidates = 0

    for hbox in horizontal:
        related_vertical = 0
        hx0 = float(hbox["x"])
        hx1 = hx0 + float(hbox["width"])
        hy0 = float(hbox["y"])
        hy1 = hy0 + max(float(hbox["height"]), 1.0)

        for vbox in vertical:
            vx0 = float(vbox["x"])
            vx1 = vx0 + max(float(vbox["width"]), 1.0)
            vy0 = float(vbox["y"])
            vy1 = vy0 + float(vbox["height"])

            if overlap(hx0, hx1, vx0, vx1) > 0 or overlap(hy0, hy1, vy0, vy1) > 0:
                related_vertical += 1

        if related_vertical >= 3:
            candidates += 1

    return min(candidates, 20)


def contour_candidate_count(elements: list[dict[str, Any]]) -> int:
    rects = [element for element in elements if element.get("kind") == "rect"]
    long_lines = []

    for element in elements:
        bbox = element.get("bbox") if isinstance(element.get("bbox"), dict) else None
        if element.get("kind") != "line" or bbox is None:
            continue

        if max(float(bbox.get("width", 0)), float(bbox.get("height", 0))) >= 20:
            long_lines.append(element)

    return min(len(rects) + math.floor(len(long_lines) / 4), 200)


def title_block_candidate(elements: list[dict[str, Any]], width: float, height: float) -> bool:
    bottom_right = 0

    for element in elements:
        bbox = element.get("bbox") if isinstance(element.get("bbox"), dict) else None
        if bbox is None:
            continue

        x = float(bbox.get("x", 0))
        y = float(bbox.get("y", 0))

        if x >= width * 0.45 and y >= height * 0.55:
            bottom_right += 1

    return bottom_right >= 8


def classify_page(text_blocks: list[dict[str, Any]], elements: list[dict[str, Any]], metrics: dict[str, int], width: float, height: float) -> tuple[str, list[str]]:
    text = "\n".join(str(block.get("text", "")) for block in text_blocks).strip()
    lower_text = text.lower()
    signals: list[str] = []

    if text == "":
        signals.append("text_empty")
    else:
        signals.append("text_blocks")

    if metrics["line_count"] > 0 or metrics["curve_count"] > 0 or metrics["rect_count"] > 0:
        signals.append("vector_geometry")

    tables = table_candidate_count(elements)
    contours = contour_candidate_count(elements)

    if tables > 0:
        signals.append("table_candidate")

    if contours > 0:
        signals.append("contour_candidate")

    if title_block_candidate(elements, width, height):
        signals.append("title_block_candidate")

    if any(marker in lower_text for marker in ["спецификац", "ведомость", "поз."]):
        role = "specification"
    elif any(marker in lower_text for marker in ["план", "плита", "сбор", "мусор", "plan"]):
        role = "plan"
    elif "title_block_candidate" in signals and metrics["line_count"] < 30:
        role = "title"
    elif metrics["line_count"] > 0 or metrics["rect_count"] > 0 or metrics["curve_count"] > 0:
        has_drawing_geometry = metrics["line_count"] >= 20 or metrics["rect_count"] >= 2 or metrics["curve_count"] > 0 or contours > 0
        role = "plan" if has_drawing_geometry else "geometry_only"
    else:
        role = "empty"

    if role in {"plan", "geometry_only"}:
        signals.append("plan_candidate")

    return role, list(dict.fromkeys(signals))


def render_preview(page: Any, preview_dir: str | None, page_number: int, filename: str) -> dict[str, Any]:
    if not preview_dir:
        return {"path": None}

    Path(preview_dir).mkdir(parents=True, exist_ok=True)
    safe_filename = "".join(ch if ch.isalnum() or ch in {"-", "_"} else "_" for ch in filename)[:80]
    output_path = Path(preview_dir) / f"{safe_filename}_page_{page_number}.png"
    pixmap = page.get_pixmap(matrix=fitz.Matrix(1.0, 1.0), alpha=False)
    pixmap.save(str(output_path))

    return {
        "path": str(output_path),
        "width": int(pixmap.width),
        "height": int(pixmap.height),
    }


def extract(args: argparse.Namespace) -> dict[str, Any]:
    document = fitz.open(args.input)
    pages = []
    max_pages = min(int(args.max_pages), len(document))
    filename = args.filename or os.path.basename(args.input)

    for page_index in range(max_pages):
        page = document.load_page(page_index)
        width = float(page.rect.width)
        height = float(page.rect.height)
        text_blocks = normalize_text_blocks(page)
        vectors, metrics = drawing_elements(page, max(1, int(args.max_vector_elements)))
        tables = table_candidate_count(vectors)
        contours = contour_candidate_count(vectors)
        role, signals = classify_page(text_blocks, vectors, metrics, width, height)
        preview = render_preview(page, args.preview_dir if args.render_preview else None, page_index + 1, filename)

        visual_metrics = {
            **metrics,
            "vector_element_count": metrics["line_count"] + metrics["curve_count"] + metrics["rect_count"],
            "stored_vector_element_count": len(vectors),
            "text_block_count": len([block for block in text_blocks if block.get("text")]),
            "table_candidate_count": tables,
            "contour_candidate_count": contours,
            "title_block_candidate_count": 1 if "title_block_candidate" in signals else 0,
            "geometry_density": round(len(vectors) / max(width * height, 1.0), 8),
        }

        pages.append(
            {
                "page_number": page_index + 1,
                "width": round(width, 4),
                "height": round(height, 4),
                "rotation": int(page.rotation),
                "text_blocks": text_blocks,
                "vector_elements": vectors,
                "visual_metrics": visual_metrics,
                "page_role": role,
                "signals": signals,
                "preview": preview,
            }
        )

    return {
        "provider": "pymupdf",
        "model": "geometry_v1",
        "pages": pages,
        "metadata": {
            "page_count": len(document),
            "processed_page_count": len(pages),
            "filename": filename,
            "pymupdf_version": getattr(fitz, "VersionBind", None),
        },
    }


def parser() -> argparse.ArgumentParser:
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--input", required=True)
    argument_parser.add_argument("--filename", default="")
    argument_parser.add_argument("--preview-dir", default="")
    argument_parser.add_argument("--max-pages", type=int, default=200)
    argument_parser.add_argument("--max-vector-elements", type=int, default=5000)
    argument_parser.add_argument("--render-preview", action="store_true")
    return argument_parser


def main() -> int:
    args = parser().parse_args()

    try:
        payload = extract(args)
    except Exception as exc:
        print(json.dumps({"error": "pdf_geometry_extract_failed", "message": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1

    print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
