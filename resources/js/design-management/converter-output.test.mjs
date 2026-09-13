import test from "node:test";
import assert from "node:assert/strict";
import { Writable } from "node:stream";
import { writeConverterMessage } from "./converter-output.mjs";

test("waits until the whole large result has been delivered before completing", async () => {
  const chunks = [];
  let release;
  const stream = new Writable({ write(chunk, encoding, callback) { chunks.push(chunk); release = callback; } });
  const payload = { event: "result", metrics: { transformations: Array.from({ length: 12000 }, (_, express_id) => ({ express_id })) } };
  let done = false;
  const pending = writeConverterMessage(stream, payload).then(() => { done = true; });
  await Promise.resolve();
  assert.equal(done, false);
  release();
  await pending;
  assert.equal(done, true);
  assert.deepEqual(JSON.parse(Buffer.concat(chunks).toString()), payload);
});
