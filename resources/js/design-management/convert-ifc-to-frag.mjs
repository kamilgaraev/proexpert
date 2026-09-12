import { readSync } from "node:fs";
import fs from "node:fs/promises";
import path from "node:path";
import { ByteBuffer } from "flatbuffers";
import pako from "pako";
import * as FRAGS from "@thatopen/fragments";
import { IfcAPI } from "web-ifc";

const [, , inputPath, outputPath, indexPath] = process.argv;
const VIEWER_GEOMETRY_PROFILE = "ifc_properties_geometry_v2";

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

const configureViewerImporter = (importer) => {
  importer.includeUniqueAttributes = true;
  importer.includeRelationNames = true;
  importer.replaceStoreyElevation = true;
  importer.distanceThreshold = null;
  importer.classes.abstract.clear();
  importer.relations.clear();
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

const value = (input) => {
  if (input === null || input === undefined) return null;
  if (typeof input !== "object") return input;
  if (Object.prototype.hasOwnProperty.call(input, "value")) return value(input.value);
  return null;
};

const namedProperties = (sets) => {
  const result = {};
  for (const set of sets ?? []) {
    const setName = String(value(set.Name) ?? "Unnamed");
    const properties = {};
    for (const property of [...(set.HasProperties ?? []), ...(set.Quantities ?? [])]) {
      const name = value(property.Name);
      if (name !== null) properties[String(name)] = value(property.NominalValue ?? property.LengthValue ?? property.AreaValue ?? property.VolumeValue ?? property.CountValue ?? property.WeightValue);
    }
    result[setName] = properties;
  }
  return result;
};

const materialNames = (materials) => (materials ?? [])
  .map((material) => value(material.Name) ?? value(material.Material?.Name))
  .filter((name) => name !== null)
  .map(String);

const relationIds = (references) => (references ?? [])
  .map((reference) => Number(value(reference)))
  .filter((id) => Number.isInteger(id) && id > 0);

const classificationMap = (api, modelID) => {
  const map = new Map();
  const relations = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName("IFCRELASSOCIATESCLASSIFICATION"));
  for (let index = 0; index < relations.size(); index += 1) {
    const relation = api.GetLine(modelID, relations.get(index), false);
    const classificationID = Number(value(relation.RelatingClassification));
    const classification = Number.isInteger(classificationID) ? api.GetLine(modelID, classificationID, false) : null;
    const name = value(classification?.Name) ?? value(classification?.Identification);
    if (name === null) continue;
    for (const relatedID of relationIds(relation.RelatedObjects)) {
      const entries = map.get(relatedID) ?? [];
      entries.push(String(name));
      map.set(relatedID, entries);
    }
  }
  return map;
};

const extractIfcIndex = async (sourcePath, destination) => {
  const api = new IfcAPI();
  await api.Init();
  const source = await fs.open(sourcePath, "r");
  const modelID = api.OpenModelFromCallback((offset, size) => {
    const buffer = new Uint8Array(size);
    const bytesRead = readSync(source.fd, buffer, 0, size, offset);
    return buffer.slice(0, bytesRead);
  });
  const output = await fs.open(destination, "w");
  let elements = 0;
  try {
    const classifications = classificationMap(api, modelID);
    const ids = api.GetAllLines(modelID);
    for (let index = 0; index < ids.size(); index += 1) {
      const expressID = ids.get(index);
      const item = api.GetLine(modelID, expressID, false);
      if (!item || !api.IsIfcElement(item.type)) continue;
      const [propertySets, materials] = await Promise.all([
        api.properties.getPropertySets(modelID, expressID, true, true),
        api.properties.getMaterialsProperties(modelID, expressID, true, true),
      ]);
      await output.write(`${JSON.stringify({
        express_id: expressID,
        global_id: value(item.GlobalId),
        category: api.GetNameFromTypeCode(item.type).toUpperCase(),
        name: value(item.Name),
        properties: namedProperties(propertySets),
        quantities: namedProperties(propertySets.filter((set) => api.GetNameFromTypeCode(set.type).toUpperCase() === "IFCELEMENTQUANTITY")),
        materials: materialNames(materials),
        classifications: classifications.get(expressID) ?? [],
      })}\n`);
      elements += 1;
    }
    const unitTypes = ["IFCSIUNIT", "IFCCONVERSIONBASEDUNIT", "IFCDERIVEDUNIT"];
    const units = unitTypes.flatMap((type) => {
      const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName(type));
      return Array.from({ length: ids.size() }, (_, index) => api.GetLine(modelID, ids.get(index), false)).map((unit) => ({
        type,
        unit_type: value(unit.UnitType),
        name: value(unit.Name),
        prefix: value(unit.Prefix),
      }));
    });
    const transformationTypes = ["IFCMAPCONVERSION", "IFCLOCALPLACEMENT", "IFCGEOMETRICREPRESENTATIONCONTEXT"];
    const transformations = transformationTypes.flatMap((type) => {
      const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName(type));
      return Array.from({ length: ids.size() }, (_, index) => ({ type, express_id: ids.get(index) }));
    });
    return { indexed_element_count: elements, units, transformations, coordination_matrix: api.GetCoordinationMatrix(modelID) };
  } finally {
    await output.close();
    await source.close();
    api.CloseModel(modelID);
  }
};

try {
  if (!inputPath || !outputPath || !indexPath) {
    throw new Error("Input and output paths are required.");
  }

  const importer = new FRAGS.IfcImporter();
  importer.wasm = {
    path: `${path.join(process.cwd(), "node_modules", "web-ifc")}${path.sep}`,
    absolute: true,
  };
  importer.webIfcSettings = { COORDINATE_TO_ORIGIN: false };
  configureViewerImporter(importer);

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
  await fs.mkdir(path.dirname(indexPath), { recursive: true });
  const ifcMetadata = await extractIfcIndex(inputPath, indexPath);
  const metrics = {
    profile: VIEWER_GEOMETRY_PROFILE,
    ifc_metadata: ifcMetadata,
    ...inspectFragments(fragmentsData, false),
  };
  await fs.writeFile(outputPath, Buffer.from(fragmentsData));
  emit({ event: "result", metrics });
  emit({ event: "progress", progress: 100, stage: "written" });
  process.exit(0);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
}
