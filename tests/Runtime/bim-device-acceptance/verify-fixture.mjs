import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { Model, SingleThreadedFragmentsModel } from '@thatopen/fragments';
import { ByteBuffer } from 'flatbuffers';
import pako from 'pako';
import { IfcAPI } from 'web-ifc';

const directory = resolve('tests/Runtime/bim-device-acceptance');
const source = await readFile(resolve('tests/Fixtures/DesignManagement/thatopen-example.ifc'));
const geometry = await readFile(resolve(directory, 'model.frag'));
const sidecar = await readFile(resolve(directory, 'properties.ndjson'));
const metrics = JSON.parse(await readFile(resolve(directory, 'fixture-metadata.json'), 'utf8'));
const rows = sidecar.toString('utf8').trim().split('\n').map(JSON.parse);
const model = new SingleThreadedFragmentsModel('device-acceptance-fixture', new Uint8Array(geometry));
const api = new IfcAPI();
await api.Init();
const sourceID = api.OpenModel(new Uint8Array(source));
try {
  const ids = new Set(await model.getLocalIds());
  assert.equal(rows.length, metrics.ifc_metadata.indexed_element_count);
  assert.equal(new Set(rows.map((row) => row.express_id)).size, rows.length);
  for (const row of rows) {
    assert.ok(ids.has(row.express_id), `fragment_missing_express_id:${row.express_id}`);
    assert.equal(api.GetLine(sourceID, row.express_id).GlobalId.value, row.global_id);
  }
  const raw = Model.getRootAsModel(new ByteBuffer(pako.inflate(geometry)));
  const meshes = raw.meshes();
  const itemIds = Array.from({ length: meshes.meshesItemsLength() }, (_, index) => meshes.meshesItems(index));
  const localIds = await model.getLocalIdsFromItemIds(itemIds);
  assert.equal(itemIds.length, localIds.length);
  for (let index = 0; index < itemIds.length; index += 1) {
    assert.equal(localIds[index], raw.localIds(itemIds[index]));
  }
  for (const id of [2863, 12954]) {
    assert.ok(localIds.includes(id), `fragment_geometry_missing_express_id:${id}`);
  }
  const report = {
    fragments_version: metrics.runtime.fragments,
    source_sha256: createHash('sha256').update(source).digest('hex'),
    geometry_sha256: createHash('sha256').update(geometry).digest('hex'),
    properties_sha256: createHash('sha256').update(sidecar).digest('hex'),
    geometry_bytes: geometry.length,
    indexed_element_count: rows.length,
    fragment_local_ids_count: ids.size,
    fragment_item_count: itemIds.length,
    missing_sidecar_ids: 0,
    source_global_id_mismatches: 0,
    express_id: 2863,
    category: rows.find((row) => row.express_id === 2863).category,
    wall_express_id: 12954,
    wall_category: rows.find((row) => row.express_id === 12954).category,
  };
  await writeFile(resolve(directory, 'fragment-identity.json'), JSON.stringify(report, null, 2) + '\n');
  process.stdout.write(JSON.stringify(report) + '\n');
} finally {
  api.CloseModel(sourceID);
  await model.dispose();
}
process.exit(0);
