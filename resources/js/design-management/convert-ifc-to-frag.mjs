import { readSync } from "node:fs";
import fs from "node:fs/promises";
import path from "node:path";
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
  await fs.writeFile(outputPath, Buffer.from(fragmentsData));
  emit({ event: "progress", progress: 100, stage: "written" });
  process.exit(0);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
}
