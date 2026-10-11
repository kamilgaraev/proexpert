export const ifcValue = (input) => {
  if (input === null || input === undefined) return null;
  if (typeof input !== "object") return input;
  return "value" in input ? ifcValue(input.value) : null;
};

const quantityValue = (property) => ifcValue(property.NominalValue ?? property.LengthValue ?? property.AreaValue ?? property.VolumeValue ?? property.CountValue ?? property.WeightValue ?? property.TimeValue ?? property.NumberValue);

export const namedProperties = (sets) => {
  const result = {};
  for (const set of sets ?? []) {
    const setName = String(ifcValue(set.Name) ?? "Unnamed");
    const properties = {};
    for (const property of [...(set.HasProperties ?? []), ...(set.Quantities ?? [])]) {
      const name = ifcValue(property?.Name);
      if (name !== null) properties[String(name)] = quantityValue(property);
    }
    result[setName] = { ...result[setName], ...properties };
  }
  return result;
};

export const createIfcIndexReader = (api, modelID, lookup) => {
  const cache = new Map();
  const read = (reference) => {
    if (reference && typeof reference === "object" && Object.hasOwn(reference, "expressID")) return reference;
    const id = Number(ifcValue(reference));
    if (!Number.isInteger(id) || id <= 0) return null;
    if (cache.has(id)) return cache.get(id);
    const line = api.GetLine(modelID, id, false);
    if (cache.size >= 256) cache.delete(cache.keys().next().value);
    cache.set(id, line);
    return line;
  };
  const typeName = (line) => line ? api.GetNameFromTypeCode(line.type).toUpperCase() : null;
  const unit = (reference, seen = new Set()) => {
    const line = read(reference);
    if (!line || seen.has(line.expressID) || seen.size >= 16) return null;
    const next = new Set([...seen, line.expressID]);
    const descriptor = {
      express_id: line.expressID,
      type: typeName(line),
      unit_type: ifcValue(line.UnitType),
      name: ifcValue(line.Name),
      prefix: ifcValue(line.Prefix),
    };
    if (line.ConversionFactor) {
      const factor = read(line.ConversionFactor);
      descriptor.conversion_factor = factor ? { value: ifcValue(factor.ValueComponent), unit: unit(factor.UnitComponent, next) } : null;
    }
    if (line.ConversionOffset !== undefined) descriptor.conversion_offset = ifcValue(line.ConversionOffset);
    if (Array.isArray(line.Elements)) descriptor.elements = line.Elements.map((reference) => {
      const element = read(reference);
      return element ? { unit: unit(element.Unit, next), exponent: ifcValue(element.Exponent) } : null;
    }).filter(Boolean);
    if (line.UserDefinedType !== undefined) descriptor.user_defined_type = ifcValue(line.UserDefinedType);
    return descriptor;
  };
  const assignedTypes = (elementID) => lookup.typeIds(elementID).map(read).filter(Boolean);
  const storeys = (elementID) => {
    const result = new Map();
    const queue = [{ id: elementID, depth: 0 }];
    const seen = new Set();
    for (let index = 0; index < queue.length && seen.size < 512; index += 1) {
      const { id, depth } = queue[index];
      if (seen.has(id) || depth > 32) continue;
      seen.add(id);
      const line = read(id);
      if (typeName(line) === "IFCBUILDINGSTOREY") {
        result.set(id, { express_id: id, name: ifcValue(line.Name), global_id: ifcValue(line.GlobalId), elevation: ifcValue(line.Elevation) });
        continue;
      }
      for (const parent of [...lookup.containerIds(id), ...lookup.parentIds(id)]) {
        if (!seen.has(parent) && queue.length < 512) queue.push({ id: parent, depth: depth + 1 });
      }
    }
    return [...result.values()];
  };
  return {
    assignedTypes,
    attributes: (item, types = assignedTypes(item.expressID)) => {
      const floors = storeys(item.expressID);
      const attributes = {
        schema_version: 2,
        predefined_type: ifcValue(item.PredefinedType),
        object_type: ifcValue(item.ObjectType),
        description: ifcValue(item.Description),
        types: types.map((type) => ({
          express_id: type.expressID,
          global_id: ifcValue(type.GlobalId),
          name: ifcValue(type.Name),
          predefined_type: ifcValue(type.PredefinedType),
          object_type: ifcValue(type.ElementType ?? type.ObjectType),
          description: ifcValue(type.Description),
        })),
        storey: floors.length === 1 ? floors[0] : null,
        storeys: floors,
      };
      if (types.length === 1) {
        attributes.type_name = attributes.types[0].name;
        attributes.type_predefined_type = attributes.types[0].predefined_type;
        attributes.type_object_type = attributes.types[0].object_type;
      }
      return attributes;
    },
    quantityUnits: (sets) => {
      const result = {};
      for (const set of sets) {
        const setName = String(ifcValue(set.Name) ?? "Unnamed");
        for (const quantity of set.Quantities ?? []) {
          const name = ifcValue(quantity?.Name);
          if (name === null) continue;
          const key = `${setName}.${name}`;
          delete result[key];
          const descriptor = unit(quantity.Unit);
          if (descriptor) result[key] = { ...descriptor, source: "quantity_unit" };
        }
      }
      return result;
    },
    projectUnits: () => {
      const result = [];
      const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName("IFCPROJECT"));
      try {
        for (let index = 0; index < ids.size(); index += 1) {
          const project = read(ids.get(index));
          const assignment = read(project?.UnitsInContext);
          for (const reference of assignment?.Units ?? []) {
            const descriptor = unit(reference);
            if (descriptor) result.push({ ...descriptor, source: "project_units_in_context", project_id: project.expressID, project_global_id: ifcValue(project.GlobalId), unit_assignment_id: assignment.expressID });
          }
        }
      } finally {
        ids.delete?.();
      }
      return result;
    },
    materialNames: (references) => {
      const names = new Set();
      const seen = new Set();
      const visit = (reference, depth = 0) => {
        const material = read(reference);
        if (!material || seen.has(material.expressID) || depth > 16) return;
        seen.add(material.expressID);
        if (typeName(material) === "IFCMATERIAL") {
          const name = ifcValue(material.Name);
          if (name !== null) names.add(String(name));
          return;
        }
        for (const field of ["Material", "Materials", "MaterialLayers", "ForLayerSet", "MaterialProfiles", "ForProfileSet", "MaterialConstituents"]) {
          for (const child of Array.isArray(material[field]) ? material[field] : [material[field]]) visit(child, depth + 1);
        }
      };
      for (const reference of references) visit(reference);
      return [...names];
    },
  };
};
