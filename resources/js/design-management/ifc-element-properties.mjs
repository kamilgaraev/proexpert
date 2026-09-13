export const getElementPropertySets = async (api, modelID, elementID) => {
  const types = await api.properties.getTypeProperties(modelID, elementID, false);
  const sets = [];
  const seen = new Set();
  for (const type of types) {
    for (const reference of type.HasPropertySets ?? []) {
      if (seen.has(reference.value)) continue;
      seen.add(reference.value);
      sets.push(await api.GetLine(modelID, reference.value, true));
    }
  }
  const ownSets = await api.properties.getPropertySets(modelID, elementID, true, false);
  return [...sets, ...ownSets];
};
