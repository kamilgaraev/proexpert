import { readFileSync, realpathSync } from 'node:fs';
import { createServer, request as upstreamRequest } from 'node:http';
import { tmpdir } from 'node:os';
import { basename, dirname } from 'node:path';

const descriptorPath = process.env.BIM_DEVICE_ACCEPTANCE_DESCRIPTOR;
const queue = [];
const active = new Map();
const sockets = new Set();
const hopHeaders = new Set(['connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade']);
let shuttingDown = false;

function descriptor() {
  if (!descriptorPath || basename(descriptorPath) !== 'descriptor.json') throw new Error('descriptor_path_invalid');
  const root = realpathSync.native(dirname(descriptorPath));
  if (dirname(root) !== realpathSync.native(tmpdir()) || !/^most-bim-device-[a-f0-9]{24}$/.test(basename(root))) throw new Error('descriptor_root_invalid');
  const data = JSON.parse(readFileSync(descriptorPath, 'utf8'));
  const env = data.environment;
  const hasControl = data.control_port !== undefined || data.control_base_url !== undefined;
  const ports = [data.http_port, data.admin_http_port, data.reverb_port, data.redis_port, ...(data.admin_worker_ports ?? []),
    ...(hasControl ? [data.control_port] : [])];
  if (env.APP_ENV !== 'testing' || env.DB_CONNECTION !== 'pgsql' || env.DB_HOST !== '127.0.0.1' || env.DB_PORT !== '55433'
    || !/^most_phpunit_[a-f0-9]{24}_testing$/.test(env.DB_DATABASE) || env.DB_USERNAME !== 'most_testing' || env.DB_PASSWORD !== 'most_testing_password'
    || realpathSync.native(data.runtime_directory) !== root || data.ui_origin !== 'http://127.0.0.1:31391'
    || env.WEB_AUTH_ADMIN_ALLOWED_ORIGINS !== data.ui_origin || Date.now() >= data.expires_at * 1000
    || !Array.isArray(data.admin_worker_ports) || data.admin_worker_ports.length !== 4
    || data.admin_worker_ports.some((port) => !Number.isInteger(port) || port < 1 || port > 65535)
    || new Set(ports).size !== (hasControl ? 9 : 8)
    || (hasControl && (!Number.isInteger(data.control_port) || data.control_port < 1 || data.control_port > 65535
      || data.control_base_url !== `http://127.0.0.1:${data.control_port}`))
    || data.admin_base_url !== `http://127.0.0.1:${data.admin_http_port}`) throw new Error('descriptor_guard_invalid');
  return data;
}

function rawHeaders(input, connection) {
  const excluded = new Set(hopHeaders);
  for (let index = 0; index < input.length; index += 2) {
    if (input[index].toLowerCase() === 'connection') {
      for (const name of input[index + 1].split(',')) excluded.add(name.trim().toLowerCase());
    }
  }
  const output = [];
  for (let index = 0; index < input.length; index += 2) {
    if (!excluded.has(input[index].toLowerCase())) output.push(input[index], input[index + 1]);
  }
  output.push('Connection', connection);
  return output;
}

function fail(response, status) {
  if (response.destroyed) return;
  if (response.headersSent) response.destroy();
  else {
    response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', Connection: 'close' });
    response.end('{"message":"bim_acceptance_gateway_transport_unavailable"}');
  }
}

function dispatch() {
  if (shuttingDown) return;
  let data;
  try { data = descriptor(); } catch { shutdown(); return; }
  for (const port of data.admin_worker_ports) {
    if ((active.get(port) ?? 0) !== 0) continue;
    let job;
    while (queue.length && !job) {
      const candidate = queue.shift();
      if (!candidate.incoming.destroyed && !candidate.outgoing.destroyed) job = candidate;
    }
    if (!job) break;
    active.set(port, 1);
    clearTimeout(job.waitTimer);
    let released = false;
    let proxied;
    const release = () => {
      if (released) return;
      released = true;
      active.set(port, 0);
      setImmediate(dispatch);
    };
    try {
      proxied = upstreamRequest({
        hostname: '127.0.0.1', port, path: job.incoming.url, method: job.incoming.method,
        headers: rawHeaders(job.incoming.rawHeaders, 'close'), agent: false,
      }, (response) => {
        job.outgoing.writeHead(response.statusCode, response.statusMessage, rawHeaders(response.rawHeaders, 'close'));
        response.once('error', () => { fail(job.outgoing, 502); release(); });
        response.once('aborted', () => { fail(job.outgoing, 502); release(); });
        response.once('end', release);
        response.pipe(job.outgoing);
      });
      proxied.setTimeout(120_000, () => proxied.destroy(new Error('upstream_timeout')));
      proxied.once('error', () => { fail(job.outgoing, 502); release(); });
      job.outgoing.once('close', () => { proxied.destroy(); release(); });
      job.incoming.once('aborted', () => { proxied.destroy(); release(); });
      job.incoming.once('error', () => { proxied.destroy(); release(); });
      job.incoming.pipe(proxied);
      job.incoming.resume();
    } catch {
      proxied?.destroy();
      fail(job.outgoing, 502);
      release();
    }
  }
}

const initial = descriptor();
const server = createServer((incoming, outgoing) => {
  try {
    const data = descriptor();
    const target = new URL(incoming.url, data.admin_base_url);
    if (!incoming.url.startsWith('/') || (!target.pathname.startsWith('/api/v1/admin/') && target.pathname !== '/__bim_acceptance/health')) {
      fail(outgoing, 404);
      return;
    }
    if (queue.length >= 128) { fail(outgoing, 503); return; }
    incoming.pause();
    const job = { incoming, outgoing, waitTimer: null };
    job.waitTimer = setTimeout(() => {
      const index = queue.indexOf(job);
      if (index >= 0) queue.splice(index, 1);
      fail(outgoing, 504);
    }, 120_000);
    outgoing.once('close', () => {
      clearTimeout(job.waitTimer);
      const index = queue.indexOf(job);
      if (index >= 0) queue.splice(index, 1);
    });
    queue.push(job);
    dispatch();
  } catch { fail(outgoing, 503); }
});
server.requestTimeout = 120_000;
server.headersTimeout = 30_000;
server.keepAliveTimeout = 1_000;
server.maxRequestsPerSocket = 1;
server.on('connection', (socket) => {
  sockets.add(socket);
  socket.once('close', () => sockets.delete(socket));
});
server.on('error', () => shutdown());

function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;
  clearInterval(expiryTimer);
  for (const job of queue.splice(0)) { clearTimeout(job.waitTimer); fail(job.outgoing, 503); }
  server.close();
  for (const socket of sockets) socket.destroy();
}

const expiryTimer = setInterval(() => { try { descriptor(); } catch { shutdown(); } }, 1_000);
process.once('SIGTERM', shutdown);
process.once('SIGINT', shutdown);
server.listen(initial.admin_http_port, '127.0.0.1');
