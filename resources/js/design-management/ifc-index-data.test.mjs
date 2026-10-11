import test from "node:test";
import assert from "node:assert/strict";
import { createIfcIndexReader, namedProperties, ifcValue } from "./ifc-index-data.mjs";

const value = (value) => ({ value });
test("preserves web-ifc numeric measures exposed by a prototype value getter", () => {
  class IfcLengthMeasure {
    constructor(number) { this._representationValue = number; }
    get value() { return this._representationValue; }
  }
  assert.equal(ifcValue(new IfcLengthMeasure(3139.9)), 3139.9);
  assert.equal(namedProperties([{ Name: value("Q"), Quantities: [{ Name: value("Length"), LengthValue: new IfcLengthMeasure(1250) }] }]).Q.Length, 1250);
});
const fixture = (records, assignments = {}) => {
  const loads = [];
  const scans = [];
  let released = 0;
  const api = {
    GetLine: (modelID, id, recursive) => { assert.equal(recursive, false); loads.push(id); return records[id]; },
    GetNameFromTypeCode: (type) => type,
    GetTypeCodeFromName: (name) => name,
    GetLineIDsWithType: (modelID, type) => {
      scans.push(type);
      const ids = Object.values(records).filter((item) => item.type === type).map((item) => item.expressID);
      return { size: () => ids.length, get: (index) => ids[index], delete: () => { released += 1; } };
    },
  };
  const lookup = {
    typeIds: (id) => assignments.types?.[id] ?? [],
    containerIds: (id) => assignments.containers?.[id] ?? [],
    parentIds: (id) => assignments.parents?.[id] ?? [],
  };
  return { reader: createIfcIndexReader(api, 0, lookup), loads, scans, released: () => released };
};

test("preserves own IFC attributes, assigned type and a storey inherited through an assembly", () => {
  const records = {
    1: { expressID: 1, type: "IFCWALL", Name: value("Wall"), PredefinedType: value("USERDEFINED"), ObjectType: value("Acoustic"), Description: value("Partition") },
    2: { expressID: 2, type: "IFCWALLTYPE", Name: value("Panel 100"), PredefinedType: value("PARTITIONING"), ElementType: value("Panel"), GlobalId: value("type-guid") },
    3: { expressID: 3, type: "IFCELEMENTASSEMBLY" },
    4: { expressID: 4, type: "IFCSPACE" },
    5: { expressID: 5, type: "IFCBUILDINGSTOREY", Name: value("Level 2"), GlobalId: value("floor-guid"), Elevation: value(3139.9) },
  };
  const { reader, loads } = fixture(records, { types: { 1: [2] }, parents: { 1: [3], 4: [5] }, containers: { 3: [4] } });
  const types = reader.assignedTypes(1);
  const result = reader.attributes(records[1], types);
  assert.equal(result.schema_version, 2);
  assert.equal(result.predefined_type, "USERDEFINED");
  assert.equal(result.object_type, "Acoustic");
  assert.equal(result.description, "Partition");
  assert.equal(result.type_name, "Panel 100");
  assert.equal(result.type_predefined_type, "PARTITIONING");
  assert.equal(result.type_object_type, "Panel");
  assert.equal(result.types[0].global_id, "type-guid");
  assert.equal(result.storey.name, "Level 2");
  assert.equal(result.storey.elevation, 3139.9);
  reader.assignedTypes(1);
  assert.equal(loads.filter((id) => id === 2).length, 1);
});

test("keeps multiple assigned types without selecting a misleading scalar type", () => {
  const { reader } = fixture({ 1: { expressID: 1, type: "IFCWALL" }, 2: { expressID: 2, type: "IFCWALLTYPE", Name: value("A") }, 3: { expressID: 3, type: "IFCWALLTYPE", Name: value("B") } }, { types: { 1: [2, 3] } });
  const result = reader.attributes({ expressID: 1, type: "IFCWALL" });
  assert.deepEqual(result.types.map((type) => type.name), ["A", "B"]);
  assert.equal(Object.hasOwn(result, "type_name"), false);
  assert.equal(result.predefined_type, null);
  assert.equal(result.storey, null);
});

test("handles absent IFC2X3 optional attributes and cycles in spatial containment", () => {
  const { reader } = fixture({ 1: { expressID: 1, type: "IFCWALL" }, 2: { expressID: 2, type: "IFCELEMENTASSEMBLY" } }, { parents: { 1: [2], 2: [1] } });
  const result = reader.attributes({ expressID: 1, type: "IFCWALL" });
  assert.equal(result.object_type, null);
  assert.equal(result.description, null);
  assert.deepEqual(result.types, []);
  assert.deepEqual(result.storeys, []);
});

test("uses only assigned project units and preserves conversion provenance", () => {
  const { reader, scans, released } = fixture({
    1: { expressID: 1, type: "IFCPROJECT", GlobalId: value("project-guid"), UnitsInContext: value(2) },
    2: { expressID: 2, type: "IFCUNITASSIGNMENT", Units: [value(3), value(5)] },
    3: { expressID: 3, type: "IFCSIUNIT", UnitType: value("LENGTHUNIT"), Name: value("METRE"), Prefix: value("MILLI") },
    4: { expressID: 4, type: "IFCSIUNIT", UnitType: value("LENGTHUNIT"), Name: value("METRE"), Prefix: null },
    5: { expressID: 5, type: "IFCCONVERSIONBASEDUNIT", UnitType: value("LENGTHUNIT"), Name: value("FOOT"), ConversionFactor: value(6) },
    6: { expressID: 6, type: "IFCMEASUREWITHUNIT", ValueComponent: value(0.3048), UnitComponent: value(4) },
  });
  const units = reader.projectUnits();
  assert.deepEqual(units.map((unit) => unit.express_id), [3, 5]);
  assert.equal(units[0].prefix, "MILLI");
  assert.equal(units[0].source, "project_units_in_context");
  assert.equal(units[0].unit_assignment_id, 2);
  assert.equal(units[1].conversion_factor.value, 0.3048);
  assert.equal(units[1].conversion_factor.unit.name, "METRE");
  assert.deepEqual(scans, ["IFCPROJECT"]);
  assert.equal(released(), 1);
});

test("keeps explicit quantity units separate and clears units on instance overrides", () => {
  const { reader } = fixture({ 4: { expressID: 4, type: "IFCSIUNIT", UnitType: value("VOLUMEUNIT"), Name: value("CUBIC_METRE") } });
  const inherited = { Name: value("BaseQuantities"), Quantities: [{ Name: value("Volume"), VolumeValue: value(2), Unit: value(4) }, { Name: value("NetVolume"), VolumeValue: value(1), Unit: value(4) }] };
  const own = { Name: value("BaseQuantities"), Quantities: [{ Name: value("Volume"), VolumeValue: value(3), Unit: null }, { Name: value("Duration"), TimeValue: value(0) }] };
  assert.equal(namedProperties([inherited, own]).BaseQuantities.Volume, 3);
  assert.equal(namedProperties([own]).BaseQuantities.Duration, 0);
  const units = reader.quantityUnits([inherited, own]);
  assert.equal(Object.hasOwn(units, "BaseQuantities.Volume"), false);
  assert.equal(units["BaseQuantities.NetVolume"].source, "quantity_unit");
  assert.equal(units["BaseQuantities.NetVolume"].name, "CUBIC_METRE");
});

test("preserves derived unit exponents, conversion offsets and unknown optional units", () => {
  const { reader } = fixture({
    1: { expressID: 1, type: "IFCDERIVEDUNIT", UnitType: value("USERDEFINED"), UserDefinedType: value("Speed"), Elements: [value(2), value(4)] },
    2: { expressID: 2, type: "IFCDERIVEDUNITELEMENT", Unit: value(3), Exponent: value(1) },
    3: { expressID: 3, type: "IFCSIUNIT", UnitType: value("LENGTHUNIT"), Name: value("METRE") },
    4: { expressID: 4, type: "IFCDERIVEDUNITELEMENT", Unit: value(5), Exponent: value(-1) },
    5: { expressID: 5, type: "IFCSIUNIT", UnitType: value("TIMEUNIT"), Name: value("SECOND") },
    6: { expressID: 6, type: "IFCCONVERSIONBASEDUNITWITHOFFSET", UnitType: value("THERMODYNAMICTEMPERATUREUNIT"), ConversionOffset: value(273.15) },
  });
  const units = reader.quantityUnits([{ Name: value("Q"), Quantities: [{ Name: value("Speed"), Unit: value(1) }, { Name: value("Temperature"), Unit: value(6) }, { Name: value("Unknown"), Unit: null }] }]);
  assert.deepEqual(units["Q.Speed"].elements.map((element) => element.exponent), [1, -1]);
  assert.equal(units["Q.Speed"].user_defined_type, "Speed");
  assert.equal(units["Q.Temperature"].conversion_offset, 273.15);
  assert.equal(Object.hasOwn(units, "Q.Unknown"), false);
});

test("retains own and layered material names without loading unrelated IFC fields", () => {
  const { reader, loads } = fixture({
    1: { expressID: 1, type: "IFCMATERIALLAYERSETUSAGE", ForLayerSet: value(2), OwnerHistory: value(99) },
    2: { expressID: 2, type: "IFCMATERIALLAYERSET", MaterialLayers: [value(3)] },
    3: { expressID: 3, type: "IFCMATERIALLAYER", Material: value(4) },
    4: { expressID: 4, type: "IFCMATERIAL", Name: value("Concrete") },
    5: { expressID: 5, type: "IFCMATERIAL", Name: value("Steel") },
  });
  assert.deepEqual(reader.materialNames([1, 5, 4]), ["Concrete", "Steel"]);
  assert.equal(loads.includes(99), false);
});

test("bounds the shared line cache instead of retaining every element", () => {
  const records = Object.fromEntries(Array.from({ length: 257 }, (_, index) => [index + 1, { expressID: index + 1, type: "IFCWALL" }]));
  const { reader, loads } = fixture(records);
  for (const item of Object.values(records)) reader.attributes(item);
  reader.attributes(records[257]);
  assert.equal(loads.length, 257);
  reader.attributes(records[1]);
  assert.equal(loads.length, 258);
});
