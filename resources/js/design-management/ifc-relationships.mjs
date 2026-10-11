const referenceIds = (references) => (Array.isArray(references) ? references : [references])
  .filter((reference) => reference !== null && reference !== undefined)
  .map((reference) => Number(typeof reference === "object" ? reference.value : reference))
  .filter((id) => Number.isInteger(id) && id > 0);

const indexRelations = (api, modelID, type, relatingField) => {
  const result = new Map();
  const ids = api.GetLineIDsWithType(modelID, api.GetTypeCodeFromName(type), true);
  try {
    for (let index = 0; index < ids.size(); index += 1) {
      const relation = api.GetLine(modelID, ids.get(index), false);
      const targets = referenceIds(relation[relatingField]);
      for (const relatedID of referenceIds(relation.RelatedObjects)) {
        const entries = result.get(relatedID) ?? [];
        for (const target of targets) entries.push(target);
        result.set(relatedID, entries);
      }
    }
  } finally {
    ids.delete?.();
  }
  return result;
};

export const createIfcPropertyLookup = (api, modelID) => {
  const types = indexRelations(api, modelID, "IFCRELDEFINESBYTYPE", "RelatingType");
  const propertyRelation = api.GetModelSchema?.(modelID) === "IFC2X3" ? "IFCRELDEFINES" : "IFCRELDEFINESBYPROPERTIES";
  const sets = indexRelations(api, modelID, propertyRelation, "RelatingPropertyDefinition");
  const materials = indexRelations(api, modelID, "IFCRELASSOCIATESMATERIAL", "RelatingMaterial");
  return {
    typeIds: (elementID) => types.get(elementID) ?? [],
    propertySetIds: (elementID) => sets.get(elementID) ?? [],
    materialIds: (elementID) => (types.get(elementID) ?? []).flatMap((typeID) => materials.get(typeID) ?? []),
  };
};
