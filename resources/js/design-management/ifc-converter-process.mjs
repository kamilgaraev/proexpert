import { fork } from "node:child_process";
import { Worker, isMainThread, workerData } from "node:worker_threads";

export const watchConverterParent = () => {
  const parentPid = Number(process.env.DESIGN_IFC_SUPERVISOR_PID);
  if (!Number.isSafeInteger(parentPid) || parentPid <= 0) throw new Error("IFC supervisor PID is required.");
  const watchdog = new Worker(new URL(import.meta.url), {
    workerData: { parentPid },
    execArgv: [],
    resourceLimits: { maxOldGenerationSizeMb: 16 },
  });
  watchdog.unref();
};

if (!isMainThread && workerData?.parentPid) {
  setInterval(() => {
    let alive = true;
    try {
      process.kill(workerData.parentPid, 0);
    } catch (error) {
      if (error.code === "ESRCH") alive = false;
    }
    if (!alive || process.ppid !== workerData.parentPid) process.kill(process.pid, "SIGKILL");
  }, 250);
}

export const runConverterPhase = (script, args, phase) => new Promise((resolve, reject) => {
  const child = fork(script, [...args, phase], {
    execArgv: ["--max-old-space-size=512"],
    stdio: ["ignore", "inherit", "inherit", "ipc"],
    env: { ...process.env, DESIGN_IFC_SUPERVISOR_PID: String(process.pid) },
  });
  let result;
  const terminate = () => child.kill("SIGTERM");
  process.once("SIGTERM", terminate);
  process.once("SIGINT", terminate);
  child.on("message", (message) => {
    if (message?.event === "phase_result" && message.phase === phase) result = message;
  });
  child.once("error", reject);
  child.once("close", (code, signal) => {
    process.removeListener("SIGTERM", terminate);
    process.removeListener("SIGINT", terminate);
    if (code !== 0 || !result) {
      reject(new Error(`IFC ${phase} phase failed (${signal ?? code ?? "no result"}).`));
      return;
    }
    resolve(result);
  });
});
