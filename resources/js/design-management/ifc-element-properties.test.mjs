import test from "node:test";
import assert from "node:assert/strict";
import { getElementPropertySets, createPropertySetReader } from "./ifc-element-properties.mjs";

test("keeps instance sets when a type has no optional property sets", async () => {
  const own = { expressID: 15, Name: { value: "Instance" } };
  const api = { properties: {
    getTypeProperties: async () => [{ HasPropertySets: null }, {}],
    getPropertySets: async (...args) => { assert.deepEqual(args, [0, 7, true, false]); return [own]; },
  }, GetLine: () => assert.fail("Empty type must not request a property set") };
  assert.deepEqual(await getElementPropertySets(api, 0, 7), [own]);
});

test("loads inherited sets once and preserves instance sets last", async () => {
  const calls = [];
  const api = { properties: {
    getTypeProperties: async () => [{ HasPropertySets: [{ value: 10 }] }, { HasPropertySets: [{ value: 10 }, { value: 11 }] }],
    getPropertySets: async () => [{ expressID: 12 }],
  }, GetLine: (...args) => { calls.push(args); return { expressID: args[1] }; } };
  assert.deepEqual(await getElementPropertySets(api, 0, 7), [{ expressID: 10 }, { expressID: 11 }, { expressID: 12 }]);
  assert.deepEqual(calls, [[0, 10, true], [0, 11, true]]);
});

test("reads canonical properties without recursively expanding owner history", async () => {
  const calls = [];
  const set = { expressID: 10, type: 100, Name: { value: "Pset" }, OwnerHistory: { value: 99 }, HasProperties: [{ value: 11 }], Quantities: [{ value: 12 }] };
  const records = {
    10: set,
    11: { Name: { value: "IsExternal" }, NominalValue: { value: true } },
    12: { Name: { value: "Volume" }, VolumeValue: { value: 2.5 } },
  };
  const api = { GetLine: (...args) => { calls.push(args); assert.equal(args[2], false); assert.notEqual(args[1], 99); return records[args[1]]; } };
  const read = createPropertySetReader(api, 0);
  const result = read(10);
  assert.equal(result.HasProperties[0].NominalValue.value, true);
  assert.equal(result.Quantities[0].VolumeValue.value, 2.5);
  assert.equal(read(10), result);
  assert.deepEqual(calls, [[0, 10, false], [0, 11, false], [0, 12, false]]);
});

test("preserves inherited and instance property sets with the bounded reader", async () => {
  const api = { properties: {
    getTypeProperties: async () => [{ HasPropertySets: [{ value: 10 }] }],
    getPropertySets: async (...args) => { assert.deepEqual(args, [0, 7, false, false]); return [{ expressID: 12 }]; },
  } };
  const calls = [];
  const read = (id, source) => { calls.push([id, source]); return { expressID: id }; };
  assert.deepEqual(await getElementPropertySets(api, 0, 7, read), [{ expressID: 10 }, { expressID: 12 }]);
  assert.deepEqual(calls, [[10, undefined], [12, { expressID: 12 }]]);
});

test("evicts old property sets instead of retaining the entire IFC property graph", () => {
  let loads = 0;
  const read = createPropertySetReader({ GetLine: (modelID, id) => { loads += 1; return { type: 100, Name: { value: String(id) } }; } }, 0);
  for (let id = 1; id <= 257; id += 1) read(id);
  read(257);
  assert.equal(loads, 257);
  read(1);
  assert.equal(loads, 258);
});
