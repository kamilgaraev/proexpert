import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import os from "node:os";
import { execFile } from "node:child_process";
import { promisify } from "node:util";

const execute = promisify(execFile);

test("real IFC preserves express IDs and canonical properties in the offline sidecar", { timeout: 60000 }, async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), "design-offline-fixture-"));
  const geometry = path.join(directory, "model.frag");
  const properties = path.join(directory, "properties.ndjson");
  const source = path.resolve("tests/Fixtures/DesignManagement/thatopen-example.ifc");

  try {
    const { stdout } = await execute(process.execPath, [
      "resources/js/design-management/convert-ifc-to-frag.mjs", source, geometry, properties,
    ], { cwd: process.cwd(), timeout: 50000, maxBuffer: 8 * 1024 * 1024 });
    const result = stdout.split("\n").filter(Boolean).map(JSON.parse).find((message) => message.event === "result");
    assert.equal(result.metrics.ifc_metadata.indexed_element_count, 120);
    assert.match(result.metrics.runtime.fragments, /^\d+\.\d+\.\d+/);

    const inspect = `
      import fs from "node:fs";
      import pako from "pako";
      import { ByteBuffer } from "flatbuffers";
      import * as FRAGS from "@thatopen/fragments";
      import { IfcAPI } from "web-ifc";
      const model = FRAGS.Model.getRootAsModel(new ByteBuffer(pako.inflate(fs.readFileSync(process.argv[1]))));
      const ids = new Set(Array.from({ length: model.localIdsLength() }, (_, index) => model.localIds(index)));
      const rows = fs.readFileSync(process.argv[2], "utf8").trim().split("\\n").map(JSON.parse);
      const api = new IfcAPI();
      await api.Init();
      const sourceID = api.OpenModel(new Uint8Array(fs.readFileSync(process.argv[3])));
      const mismatches = rows.filter((row) => api.GetLine(sourceID, row.express_id).GlobalId.value !== row.global_id);
      console.log(JSON.stringify({
        missing: rows.filter((row) => !ids.has(row.express_id)).map((row) => row.express_id),
        mismatches: mismatches.map((row) => row.express_id),
        wall: rows.find((row) => row.express_id === 12954),
        beam: rows.find((row) => row.express_id === 12881),
      }));
      api.CloseModel(sourceID);
      process.exit(0);
    `;
    const inspection = await execute(process.execPath, ["--input-type=module", "--eval", inspect, geometry, properties, source], {
      cwd: process.cwd(), timeout: 10000,
    });
    const report = JSON.parse(inspection.stdout.trim());
    assert.deepEqual(report.missing, []);
    assert.deepEqual(report.mismatches, []);
    assert.equal(report.wall.properties.Pset_WallCommon.IsExternal, true);
    assert.equal(report.wall.properties.Pset_WallCommon.LoadBearing, true);
    assert.equal(report.wall.properties.Pset_WallCommon.Reference, "150 Concrete");
    assert.deepEqual(report.wall.classifications, ["Exterior Walls"]);
    assert.deepEqual(report.beam.materials, [" <Unnamed>"]);
  } finally {
    for (const file of [geometry, properties]) await fs.unlink(file).catch(() => {});
    await fs.rmdir(directory);
  }
});
