# ThatOpen IFC acceptance fixture

Source: `ThatOpen/engine_web-ifc`, `examples/example.ifc`, main branch.

- Raw source: https://raw.githubusercontent.com/ThatOpen/engine_web-ifc/main/examples/example.ifc
- Repository license: MPL-2.0 (`LICENSE.md` in the source repository).
- Downloaded SHA-256: `db372f3f57796e2f572958c1c144bf3d8be7912493738636a2152cf18f08a14d`
- File size: 413,681 bytes.

Acceptance run on 2026-09-09 used `node resources/js/design-management/convert-ifc-to-frag.mjs` with the fixture path, a local `.frag` output path and a local NDJSON index path. It completed successfully in 2,923 ms: 44,115-byte fragment, 55,657-byte index, 120 indexed IFC elements, renderable geometry (121 local IDs, 120 samples, 82 representations) and a valid bounding box. The index included IFC units, coordinate transformations, GlobalIds, property sets and classifications. The local run artifacts are intentionally kept under `storage/app` and are not fixtures.
