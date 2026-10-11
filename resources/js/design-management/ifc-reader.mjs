import { readSync } from "node:fs";
import fs from "node:fs/promises";

export const IFC_LOADER_SETTINGS = Object.freeze({
  COORDINATE_TO_ORIGIN: false,
  TAPE_SIZE: 64 * 1024 * 1024,
  MEMORY_LIMIT: 512 * 1024 * 1024,
});

export const openIfcSource = async (filename) => {
  const handle = await fs.open(filename, "r");
  let buffer = new Uint8Array(64 * 1024);
  return {
    read(offset, size) {
      if (buffer.length < size) buffer = new Uint8Array(size);
      const bytesRead = readSync(handle.fd, buffer, 0, size, offset);
      return buffer.subarray(0, bytesRead);
    },
    close: () => handle.close(),
  };
};
