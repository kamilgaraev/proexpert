import test from "node:test";
import assert from "node:assert/strict";
import { createIfcPropertyLookup } from "./ifc-relationships.mjs";
import { getElementPropertySets, createPropertySetReader } from "./ifc-element-properties.mjs";

test("indexes IFC relations once and preserves type properties, own overrides and inherited materials", async () => {
  let scans = 0;
  let released = 0;
  const groups = {
    IFCRELDEFINESBYTYPE: [1],
    IFCRELDEFINESBYPROPERTIES: [2, 3],
    IFCRELASSOCIATESMATERIAL: [4, 5],
  };
  const records = {
    1: { RelatedObjects: [{ value: 7 }, { value: 8 }], RelatingType: { value: 10 } },
    2: { RelatedObjects: [{ value: 7 }], RelatingPropertyDefinition: { value: 12 } },
    3: { RelatedObjects: [{ value: 8 }], RelatingPropertyDefinition: { value: 13 } },
    4: { RelatedObjects: [{ value: 10 }], RelatingMaterial: { value: 20 } },
    5: { RelatedObjects: [{ value: 7 }], RelatingMaterial: { value: 21 } },
    10: { HasPropertySets: [{ value: 11 }] },
    11: { expressID: 11, type: 100, Name: { value: "Inherited" } },
    12: { expressID: 12, type: 100, Name: { value: "Own" } },
    13: { expressID: 13, type: 100, Name: { value: "Other" } },
  };
  const api = {
    properties: { getTypeProperties: () => assert.fail("Must not rescan inverse relations"), getPropertySets: () => assert.fail("Must not rescan inverse relations") },
    GetTypeCodeFromName: (name) => name,
    GetLineIDsWithType: (modelID, type, inherited) => {
      scans += 1;
      assert.equal(inherited, true);
      return { size: () => (groups[type] ?? []).length, get: (index) => groups[type][index], delete: () => { released += 1; } };
    },
    GetLine: (modelID, id, recursive) => { assert.equal(recursive, false); return records[id]; },
  };
  const lookup = createIfcPropertyLookup(api, 0);
  assert.equal(scans, 6);
  assert.equal(released, 6);
  assert.deepEqual(lookup.materialIds(7), [20, 21]);
  assert.deepEqual(lookup.materialIds(8), [20]);
  const read = createPropertySetReader(api, 0);
  assert.deepEqual((await getElementPropertySets(api, 0, 7, read, lookup)).map((set) => set.Name.value), ["Inherited", "Own"]);
  assert.deepEqual((await getElementPropertySets(api, 0, 8, read, lookup)).map((set) => set.Name.value), ["Inherited", "Other"]);
  assert.equal(scans, 6);
  assert.deepEqual(lookup.typeIds(99), []);
  assert.deepEqual(lookup.propertySetIds(99), []);
});

test("preserves IFC2X3 IsDefinedBy order when property sets share names", async () => {
  const groups = { IFCRELDEFINESBYTYPE: [1], IFCRELDEFINES: [1, 2, 3], IFCRELDEFINESBYPROPERTIES: [3, 2], IFCRELASSOCIATESMATERIAL: [] };
  const records = {
    1: { RelatedObjects: [{ value: 7 }], RelatingType: { value: 10 } },
    2: { RelatedObjects: [{ value: 7 }], RelatingPropertyDefinition: { value: 20 } },
    3: { RelatedObjects: [{ value: 7 }], RelatingPropertyDefinition: { value: 21 } },
    10: {},
    20: { expressID: 20, type: 100, Name: { value: "Shared" }, HasProperties: [{ value: 30 }] },
    21: { expressID: 21, type: 100, Name: { value: "Shared" }, HasProperties: [{ value: 31 }] },
    30: { Name: { value: "Value" }, NominalValue: { value: 1 } },
    31: { Name: { value: "Value" }, NominalValue: { value: 2 } },
  };
  const api = {
    GetModelSchema: () => "IFC2X3",
    GetTypeCodeFromName: (name) => name,
    GetLineIDsWithType: (modelID, type) => ({ size: () => (groups[type] ?? []).length, get: (index) => groups[type][index] }),
    GetLine: (modelID, id) => records[id],
  };
  const lookup = createIfcPropertyLookup(api, 0);
  assert.deepEqual(lookup.propertySetIds(7), [20, 21]);
  const sets = await getElementPropertySets(api, 0, 7, createPropertySetReader(api, 0), lookup);
  const properties = Object.assign({}, ...sets.map((set) => Object.fromEntries(set.HasProperties.map((property) => [property.Name.value, property.NominalValue.value]))));
  assert.equal(properties.Value, 2);
});
