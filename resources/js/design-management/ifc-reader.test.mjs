import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import path from "node:path";
import os from "node:os";
import { openIfcSource, IFC_LOADER_SETTINGS } from "./ifc-reader.mjs";

test("bounds the parser cache and reads exactly the requested IFC ranges", async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), "design-ifc-reader-"));
  const filename = path.join(directory, "source.ifc");
  const contents = Buffer.from("ISO-10303-21;\nDATA;\nENDSEC;");
  await fs.writeFile(filename, contents);
  let source;
  try {
    source = await openIfcSource(filename);
    assert.equal(IFC_LOADER_SETTINGS.MEMORY_LIMIT, 512 * 1024 * 1024);
    assert.equal(IFC_LOADER_SETTINGS.TAPE_SIZE, 64 * 1024 * 1024);
    assert.equal(IFC_LOADER_SETTINGS.COORDINATE_TO_ORIGIN, false);
    assert.deepEqual(Buffer.from(source.read(0, 3)), contents.subarray(0, 3));
    assert.deepEqual(Buffer.from(source.read(3, 12)), contents.subarray(3, 15));
    assert.deepEqual(Buffer.from(source.read(contents.length - 2, 64)), contents.subarray(-2));
    assert.equal(source.read(contents.length, 64).length, 0);
    assert.deepEqual(Buffer.from(source.read(0, contents.length)), contents);
  } finally {
    await source?.close();
    await fs.unlink(filename);
    await fs.rmdir(directory);
  }
});
