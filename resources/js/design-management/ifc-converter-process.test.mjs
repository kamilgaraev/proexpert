import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fork } from "node:child_process";
import { once } from "node:events";
import { runConverterPhase } from "./ifc-converter-process.mjs";

const withWorker = async (contents, callback) => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), "design-ifc-phase-"));
  const script = path.join(directory, "worker.mjs");
  const marker = path.join(directory, "closed");
  await fs.writeFile(script, contents);
  try {
    await callback(script, marker);
  } finally {
    await fs.unlink(marker).catch(() => {});
    await fs.unlink(script);
    await fs.rmdir(directory);
  }
};

test("waits for the geometry process to finish before starting the next phase", async () => {
  await withWorker(`
    import fs from "node:fs/promises";
    process.send({ event: "phase_result", phase: "geometry", metrics: { geometry: true } });
    setTimeout(async () => {
      await fs.writeFile(process.argv[2], "finished");
      process.exit(0);
    }, 50);
  `, async (script, marker) => {
    const result = await runConverterPhase(script, [marker], "geometry");
    assert.equal(await fs.readFile(marker, "utf8"), "finished");
    assert.deepEqual(result.metrics, { geometry: true });
  });
});

test("rejects a failed phase even when it emitted a result", async () => {
  await withWorker(`
    process.send({ event: "phase_result", phase: "geometry", metrics: {} }, () => process.exit(7));
  `, async (script) => {
    await assert.rejects(runConverterPhase(script, [], "geometry"), /geometry phase failed \(7\)/);
  });
});

test("rejects a phase that exits without its completion result", async () => {
  await withWorker("process.exit(0);", async (script) => {
    await assert.rejects(runConverterPhase(script, [], "index"), /index phase failed/);
  });
});

test("stops a synchronously busy converter when its supervisor is killed", { timeout: 10000 }, async () => {
  const moduleUrl = new URL("./ifc-converter-process.mjs", import.meta.url).href;
  await withWorker(`
    import { watchConverterParent } from ${JSON.stringify(moduleUrl)};
    watchConverterParent();
    process.stdout.write(JSON.stringify({ pid: process.pid }) + "\\n", () => {
      for (;;) {}
    });
  `, async (script) => {
    const supervisorScript = path.join(path.dirname(script), "supervisor.mjs");
    await fs.writeFile(supervisorScript, `
      import { runConverterPhase } from ${JSON.stringify(moduleUrl)};
      await runConverterPhase(process.argv[2], [], "geometry");
    `);
    const supervisor = fork(supervisorScript, [script], { stdio: ["ignore", "pipe", "pipe", "ipc"] });
    let childPid;
    let timer;
    let converterClosed = false;
    try {
      const [chunk] = await once(supervisor.stdout, "data");
      childPid = JSON.parse(chunk.toString()).pid;
      const closed = once(supervisor, "close");
      supervisor.kill("SIGKILL");
      await Promise.race([
        closed,
        new Promise((_, reject) => { timer = setTimeout(() => reject(new Error("Orphan IFC converter stayed alive.")), 3000); }),
      ]);
      converterClosed = true;
    } finally {
      clearTimeout(timer);
      supervisor.kill("SIGKILL");
      if (childPid && !converterClosed) {
        try { process.kill(childPid, "SIGKILL"); } catch (error) { if (error.code !== "ESRCH") throw error; }
      }
      await fs.unlink(supervisorScript);
    }
  });
});
