import { readSync } from "node:fs";
import fs from "node:fs/promises";
import path from "node:path";
import { ByteBuffer } from "flatbuffers";
import pako from "pako";
import * as FRAGS from "@thatopen/fragments";

const [, , inputPath, outputPath] = process.argv;

const emit = (payload) => {
  process.stdout.write(`${JSON.stringify(payload)}\n`);
};

const normalizeProgress = (progress) => {
  if (typeof progress !== "number" || Number.isNaN(progress)) {
    return 0;
  }

  const percent = progress <= 1 ? progress * 100 : progress;

  return Math.max(0, Math.min(100, percent));
};

const emptyBounds = () => ({
  min: { x: Infinity, y: Infinity, z: Infinity },
  max: { x: -Infinity, y: -Infinity, z: -Infinity },
});

const vector = (value) => {
  if (!value) {
    return null;
  }

  return {
    x: value.x(),
    y: value.y(),
    z: value.z(),
  };
};

const expandBounds = (bounds, box) => {
  const min = vector(box?.min());
  const max = vector(box?.max());

  if (!min || !max) {
    return false;
  }

  for (const axis of ["x", "y", "z"]) {
    if (!Number.isFinite(min[axis]) || !Number.isFinite(max[axis]) || max[axis] < min[axis]) {
      return false;
    }

    bounds.min[axis] = Math.min(bounds.min[axis], min[axis]);
    bounds.max[axis] = Math.max(bounds.max[axis], max[axis]);
  }

  return true;
};

const hasValidBounds = (bounds) => {
  for (const axis of ["x", "y", "z"]) {
    if (!Number.isFinite(bounds.min[axis]) || !Number.isFinite(bounds.max[axis]) || bounds.max[axis] < bounds.min[axis]) {
      return false;
    }
  }

  return bounds.max.x > bounds.min.x || bounds.max.y > bounds.min.y || bounds.max.z > bounds.min.z;
};

const inspectFragments = (bytes, raw = false) => {
  const fragmentBytes = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  const modelBytes = raw ? fragmentBytes : pako.inflate(fragmentBytes);
  const model = FRAGS.Model.getRootAsModel(new ByteBuffer(modelBytes));
  const meshes = model.meshes();
  const bounds = emptyBounds();
  let boundedRepresentations = 0;

  const metrics = {
    format: "thatopen_frag",
    raw,
    local_id_count: model.localIdsLength(),
    category_count: model.categoriesLength(),
    sample_count: meshes?.samplesLength() ?? 0,
    representation_count: meshes?.representationsLength() ?? 0,
    shell_count: meshes?.shellsLength() ?? 0,
    bounding_box: null,
  };

  for (let index = 0; index < metrics.representation_count; index += 1) {
    const representation = meshes.representations(index);
    const box = representation?.bbox();

    if (expandBounds(bounds, box)) {
      boundedRepresentations += 1;
    }
  }

  if (boundedRepresentations > 0 && hasValidBounds(bounds)) {
    metrics.bounding_box = bounds;
  }

  if (metrics.local_id_count <= 0 || metrics.sample_count <= 0 || metrics.representation_count <= 0) {
    throw new Error("Prepared viewer file does not contain renderable BIM geometry.");
  }

  if (!metrics.bounding_box) {
    throw new Error("Prepared viewer file has an invalid BIM bounding box.");
  }

  return metrics;
};

try {
  if (!inputPath || !outputPath) {
    throw new Error("Input and output paths are required.");
  }

  const importer = new FRAGS.IfcImporter();
  importer.wasm = {
    path: `${path.join(process.cwd(), "node_modules", "web-ifc")}${path.sep}`,
    absolute: true,
  };
  importer.webIfcSettings = {
    COORDINATE_TO_ORIGIN: true,
  };
  importer.includeUniqueAttributes = false;
  importer.includeRelationNames = false;
  importer.replaceStoreyElevation = true;
  importer.distanceThreshold = null;

  emit({ event: "progress", progress: 0, stage: "reading" });
  const handle = await fs.open(inputPath, "r");
  const chunkSize = 1024 * 1024;
  const readCallback = (offset) => {
    const buffer = new Uint8Array(chunkSize);
    const bytesRead = readSync(handle.fd, buffer, 0, chunkSize, offset);

    return buffer.slice(0, bytesRead);
  };

  emit({ event: "progress", progress: 5, stage: "converting" });
  let fragmentsData;
  try {
    fragmentsData = await importer.process({
      readFromCallback: true,
      readCallback,
      raw: false,
      progressCallback: (progress) => {
        emit({ event: "progress", progress: normalizeProgress(progress), stage: "converting" });
      },
    });
  } finally {
    await handle.close();
  }

  await fs.mkdir(path.dirname(outputPath), { recursive: true });
  const metrics = inspectFragments(fragmentsData, false);
  await fs.writeFile(outputPath, Buffer.from(fragmentsData));
  emit({ event: "result", metrics });
  emit({ event: "progress", progress: 100, stage: "written" });
  process.exit(0);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
}
