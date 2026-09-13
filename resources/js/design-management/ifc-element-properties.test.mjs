import test from "node:test";
import assert from "node:assert/strict";
import { getElementPropertySets } from "./ifc-element-properties.mjs";

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
