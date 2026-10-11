export const createPropertySetReader = (api, modelID) => {
  const cache = new Map();
  return (id, source) => {
    if (cache.has(id)) return cache.get(id);
    const set = source ?? api.GetLine(modelID, id, false);
    const resolve = (references) => (references ?? []).map((reference) => api.GetLine(modelID, reference.value, false));
    const result = {
      type: set.type,
      Name: set.Name,
      HasProperties: resolve(set.HasProperties),
      Quantities: resolve(set.Quantities),
    };
    if (cache.size >= 256) cache.delete(cache.keys().next().value);
    cache.set(id, result);
    return result;
  };
};

export const getElementPropertySets = async (api, modelID, elementID, readPropertySet, lookup, assignedTypes) => {
  const types = assignedTypes ?? (lookup
    ? lookup.typeIds(elementID).map((id) => api.GetLine(modelID, id, false))
    : await api.properties.getTypeProperties(modelID, elementID, false));
  const sets = [];
  const seen = new Set();
  for (const type of types) {
    for (const reference of type.HasPropertySets ?? []) {
      if (seen.has(reference.value)) continue;
      seen.add(reference.value);
      sets.push(readPropertySet
        ? await readPropertySet(reference.value)
        : await api.GetLine(modelID, reference.value, true));
    }
  }
  const ownSets = lookup
    ? lookup.propertySetIds(elementID).map((id) => api.GetLine(modelID, id, !readPropertySet))
    : await api.properties.getPropertySets(modelID, elementID, !readPropertySet, false);
  return [...sets, ...(readPropertySet
    ? await Promise.all(ownSets.map((set) => readPropertySet(set.expressID, set)))
    : ownSets)];
};
