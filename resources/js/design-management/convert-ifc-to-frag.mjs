import { createWriteStream } from "node:fs";
import fs from "node:fs/promises";
import path from "node:path";
import { createRequire } from "node:module";
import { Readable } from "node:stream";
import { pipeline } from "node:stream/promises";
import { createDeflate } from "node:zlib";
import { ByteBuffer } from "flatbuffers";
import { getElementPropertySets, createPropertySetReader } from "./ifc-element-properties.mjs";
import { writeConverterMessage } from "./converter-output.mjs";
import { openIfcSource, IFC_LOADER_SETTINGS } from "./ifc-reader.mjs";
import { runConverterPhase, watchConverterParent } from "./ifc-converter-process.mjs";
import { createIfcPropertyLookup } from "./ifc-relationships.mjs";

const [, , inputPath, outputPath, indexPath, phase] = process.argv;
const VIEWER_GEOMETRY_PROFILE = "ifc_properties_geometry_v2";
const require = createRequire(import.meta.url);

const dependencyVersion = async (name) => {
  let directory = path.dirname(require.resolve(name));
  while (directory !== path.dirname(directory)) {
    try {
      const metadata = JSON.parse(await fs.readFile(path.join(directory, "package.json"), "utf8"));
      if (metadata.name === name && typeof metadata.version === "string") return metadata.version;
    } catch (error) {
      if (error?.code !== "ENOENT") throw error;
    }
    directory = path.dirname(directory);
  }
  throw new Error(`Runtime version is unavailable: ${name}`);
};

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

const inspectFragments = async (bytes) => {
  const { Model } = await import("@thatopen/fragments");
  const fragmentBytes = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  const model = Model.getRootAsModel(new ByteBuffer(fragmentBytes));
  const meshes = model.meshes();
  const bounds = emptyBounds();
  let boundedRepresentations = 0;

  const metrics = {
    format: "thatopen_frag",
    raw: false,
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
    result[setName] = { ...result[setName], ...properties };
  }
  return result;
};

const materialNames = (api, modelID, materials) => (materials ?? [])
  .map((material) => {
    const name = value(material.Name);
    if (name !== null) return name;
    const materialID = Number(value(material.Material));
    return Number.isInteger(materialID) && materialID > 0 ? value(api.GetLine(modelID, materialID, false)?.Name) : null;
  })
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
  relations.delete?.();
  return map;
};

const extractIfcIndex = async (sourcePath, destination) => {
  const { IfcAPI } = await import("web-ifc");
  const api = new IfcAPI();
  let source;
  let output;
  let modelID;
  const elementGroups = [];
  let elements = 0;
  try {
    await api.Init();
    source = await openIfcSource(sourcePath);
    modelID = api.OpenModelFromCallback(source.read, IFC_LOADER_SETTINGS);
    if (modelID < 0) throw new Error("Source IFC model could not be opened.");
    output = await fs.open(destination, "w");
    const readPropertySet = createPropertySetReader(api, modelID);
    const propertyLookup = createIfcPropertyLookup(api, modelID);
    const classifications = classificationMap(api, modelID);
    for (const { typeID } of api.GetAllTypesOfModel(modelID)) {
      if (api.IsIfcElement(typeID)) elementGroups.push(api.GetLineIDsWithType(modelID, typeID));
    }
    const totalElements = elementGroups.reduce((total, ids) => total + ids.size(), 0);
    for (const ids of elementGroups) {
      for (let index = 0; index < ids.size(); index += 1) {
        const expressID = ids.get(index);
        const item = api.GetLine(modelID, expressID, false);
        if (!item || !api.IsIfcElement(item.type)) continue;
        const [propertySets, materials] = await Promise.all([
          getElementPropertySets(api, modelID, expressID, readPropertySet, propertyLookup),
          propertyLookup.materialIds(expressID).map((id) => api.GetLine(modelID, id, false)),
        ]);
        await output.writeFile(`${JSON.stringify({
          express_id: expressID,
          global_id: value(item.GlobalId),
          category: api.GetNameFromTypeCode(item.type).toUpperCase(),
          name: value(item.Name),
          properties: namedProperties(propertySets),
          quantities: namedProperties(propertySets.filter((set) => api.GetNameFromTypeCode(set.type).toUpperCase() === "IFCELEMENTQUANTITY")),
          materials: materialNames(api, modelID, materials),
          classifications: classifications.get(expressID) ?? [],
        })}\n`);
        elements += 1;
        if (elements % 250 === 0 || elements === totalElements) {
          emit({ event: "progress", progress: 80 + 15 * elements / totalElements, stage: "indexing" });
        }
      }
    }
    const unitTypes = ["IFCSIUNIT", "IFCCONVERSIONBASEDUNIT", "IFCDERIVEDUNIT"];
    const units = unitTypes.flatMap((type) => {
      const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName(type));
      const units = Array.from({ length: ids.size() }, (_, index) => api.GetLine(modelID, ids.get(index), false)).map((unit) => ({
        type,
        unit_type: value(unit.UnitType),
        name: value(unit.Name),
        prefix: value(unit.Prefix),
      }));
      ids.delete?.();
      return units;
    });
    const transformationTypes = ["IFCMAPCONVERSION", "IFCLOCALPLACEMENT", "IFCGEOMETRICREPRESENTATIONCONTEXT"];
    const transformations = transformationTypes.flatMap((type) => {
      const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName(type));
      const transformations = Array.from({ length: ids.size() }, (_, index) => ({ type, express_id: ids.get(index) }));
      ids.delete?.();
      return transformations;
    });
    return { indexed_element_count: elements, units, transformations, coordination_matrix: api.GetCoordinationMatrix(modelID) };
  } finally {
    for (const ids of elementGroups) ids.delete?.();
    if (modelID >= 0) api.CloseModel(modelID);
    api.Dispose();
    await Promise.all([output?.close(), source?.close()]);
  }
};

const memoryUsage = () => ({ pid: process.pid, peak_rss_kib: process.resourceUsage().maxRSS });

const completePhase = (metrics) => new Promise((resolve, reject) => {
  if (!process.send) {
    reject(new Error("IFC phase requires a supervising process."));
    return;
  }
  process.send({ event: "phase_result", phase, metrics, memory: memoryUsage() }, (error) => error ? reject(error) : resolve());
});

if (process.send) process.once("disconnect", () => process.exit(1));

try {
  if (process.send) watchConverterParent();
  if (!inputPath || !outputPath || !indexPath) {
    throw new Error("Input and output paths are required.");
  }

  if (!phase) {
    await fs.mkdir(path.dirname(outputPath), { recursive: true });
    await fs.mkdir(path.dirname(indexPath), { recursive: true });
    emit({ event: "progress", progress: 0, stage: "reading" });
    const script = new URL(import.meta.url);
    const args = [inputPath, outputPath, indexPath];
    const geometry = await runConverterPhase(script, args, "geometry");
    emit({ event: "progress", progress: 80, stage: "indexing" });
    const index = await runConverterPhase(script, args, "index");
    await writeConverterMessage(process.stdout, {
      event: "result",
      metrics: {
        ...geometry.metrics,
        ifc_metadata: index.metrics,
        memory: { geometry: geometry.memory, index: index.memory, parser_cache_limit_bytes: IFC_LOADER_SETTINGS.MEMORY_LIMIT },
      },
    });
    await writeConverterMessage(process.stdout, { event: "progress", progress: 100, stage: "written" });
    process.exit(0);
  }

  if (phase === "index") {
    await completePhase(await extractIfcIndex(inputPath, indexPath));
    process.exit(0);
  }
  if (phase !== "geometry") throw new Error("Unknown IFC processing phase.");

  const { IfcImporter } = await import("@thatopen/fragments");
  const importer = new IfcImporter();
  importer.wasm = {
    path: `${path.join(process.cwd(), "node_modules", "web-ifc")}${path.sep}`,
    absolute: true,
  };
  importer.webIfcSettings = IFC_LOADER_SETTINGS;
  configureViewerImporter(importer);

  const source = await openIfcSource(inputPath);

  emit({ event: "progress", progress: 5, stage: "converting" });
  let fragmentsData;
  try {
    fragmentsData = await importer.process({
      readFromCallback: true,
      readCallback: source.read,
      raw: true,
      progressCallback: (progress) => {
        emit({ event: "progress", progress: 5 + 0.75 * normalizeProgress(progress), stage: "converting" });
      },
    });
  } finally {
    await source.close();
  }

  const metrics = {
    profile: VIEWER_GEOMETRY_PROFILE,
    runtime: {
      fragments: await dependencyVersion("@thatopen/fragments"),
      web_ifc: await dependencyVersion("web-ifc"),
      three: await dependencyVersion("three"),
      node: process.versions.node,
    },
    ...await inspectFragments(fragmentsData),
  };
  const chunks = function* () {
    for (let offset = 0; offset < fragmentsData.length; offset += 64 * 1024) {
      yield fragmentsData.subarray(offset, Math.min(offset + 64 * 1024, fragmentsData.length));
    }
  };
  await pipeline(Readable.from(chunks()), createDeflate(), createWriteStream(outputPath));
  await completePhase(metrics);
  process.exit(0);
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exit(1);
}
