import test from "node:test";
import assert from "node:assert/strict";
import { IfcAPI } from "web-ifc";
import { createIfcPropertyLookup } from "./ifc-relationships.mjs";
import { createIfcIndexReader, namedProperties } from "./ifc-index-data.mjs";
import { createPropertySetReader, getElementPropertySets } from "./ifc-element-properties.mjs";

test("real IFC4 keeps numeric quantities, explicit units, own attributes, type and floor", async () => {
  const source = `ISO-10303-21;
HEADER;
FILE_DESCRIPTION(('ViewDefinition [CoordinationView]'),'2;1');
FILE_NAME('test.ifc','2026-10-11T00:00:00',(),(),'test','test','');
FILE_SCHEMA(('IFC4'));
ENDSEC;
DATA;
#1=IFCPROJECT('0123456789012345678901',$,'Project',$,$,$,$,$,#2);
#2=IFCUNITASSIGNMENT((#3));
#3=IFCSIUNIT(*,.LENGTHUNIT.,.MILLI.,.METRE.);
#4=IFCSIUNIT(*,.VOLUMEUNIT.,$,.CUBIC_METRE.);
#10=IFCWALL('0123456789012345678902',$,'Wall','Desc','Instance',$,$,$,.USERDEFINED.);
#11=IFCWALLTYPE('0123456789012345678903',$,'Panel','Type Desc',$,$,$,$,'PanelType',.PARTITIONING.);
#12=IFCRELDEFINESBYTYPE('0123456789012345678904',$,$,$,(#10),#11);
#20=IFCBUILDINGSTOREY('0123456789012345678905',$,'Floor',$,$,$,$,$,.ELEMENT.,3000.);
#21=IFCRELCONTAINEDINSPATIALSTRUCTURE('0123456789012345678906',$,$,$,(#10),#20);
#30=IFCQUANTITYVOLUME('Volume',$,#4,2.5,$);
#31=IFCELEMENTQUANTITY('0123456789012345678907',$,'BaseQuantities',$,$,(#30));
#32=IFCRELDEFINESBYPROPERTIES('0123456789012345678908',$,$,$,(#10),#31);
ENDSEC;
END-ISO-10303-21;`;
  const api = new IfcAPI();
  await api.Init();
  const modelID = api.OpenModel(new TextEncoder().encode(source));
  try {
    const lookup = createIfcPropertyLookup(api, modelID);
    const reader = createIfcIndexReader(api, modelID, lookup);
    const types = reader.assignedTypes(10);
    const attributes = reader.attributes(api.GetLine(modelID, 10, false), types);
    assert.equal(attributes.predefined_type, "USERDEFINED");
    assert.equal(attributes.object_type, "Instance");
    assert.equal(attributes.description, "Desc");
    assert.equal(attributes.type_name, "Panel");
    assert.equal(attributes.type_object_type, "PanelType");
    assert.equal(attributes.type_predefined_type, "PARTITIONING");
    assert.equal(attributes.storey.name, "Floor");
    assert.equal(attributes.storey.elevation, 3000);
    const sets = await getElementPropertySets(api, modelID, 10, createPropertySetReader(api, modelID), lookup, types);
    assert.equal(namedProperties(sets).BaseQuantities.Volume, 2.5);
    assert.equal(reader.quantityUnits(sets)["BaseQuantities.Volume"].name, "CUBIC_METRE");
    assert.equal(reader.quantityUnits(sets)["BaseQuantities.Volume"].source, "quantity_unit");
    assert.deepEqual(reader.projectUnits().map((unit) => unit.unit_type), ["LENGTHUNIT"]);
  } finally {
    api.CloseModel(modelID);
    api.Dispose();
  }
});
