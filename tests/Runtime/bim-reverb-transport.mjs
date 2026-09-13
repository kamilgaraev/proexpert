import { createHmac, createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import assert from 'node:assert/strict';

const channel = 'presence-design-model-session.1';
const clients = [];
const sent = new Map();
const latencies = [];
const until = async (predicate, label) => {
  const deadline = Date.now() + 10000;
  while (!predicate()) {
    if (Date.now() > deadline) throw new Error(`Timeout: ${label}`);
    await new Promise(resolve => setTimeout(resolve, 20));
  }
};
const sign = value => createHmac('sha256', 'bim-local-secret').update(value).digest('hex');
const connect = async id => {
  const client = { id, members: new Set(), received: new Set(), errors: [], ready: false };
  const ws = new WebSocket('ws://127.0.0.1:18079/app/bim-local-key?protocol=7&client=local-test&version=1.0');
  client.ws = ws;
  clients.push(client);
  ws.addEventListener('error', () => client.errors.push('websocket error'));
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    const data = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;
    if (message.event === 'pusher:connection_established') {
      const channelData = JSON.stringify({ user_id: String(id), user_info: { id, name: `Participant ${id}` } });
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: {
        channel, channel_data: channelData, auth: `bim-local-key:${sign(`${data.socket_id}:${channel}:${channelData}`)}`,
      } }));
    } else if (message.event === 'pusher_internal:subscription_succeeded') {
      client.members = new Set(data.presence.ids.map(String)); client.ready = true;
    } else if (message.event === 'pusher_internal:member_added') client.members.add(String(data.user_id));
    else if (message.event === 'pusher_internal:member_removed') client.members.delete(String(data.user_id));
    else if (message.event === 'design-model-session.transient') {
      const key = `${data.sender.id}:${data.payload.x}`;
      if (client.received.has(key)) client.errors.push(`duplicate ${key}`);
      client.received.add(key);
      latencies.push(performance.now() - sent.get(key));
    } else if (message.event === 'pusher:error') client.errors.push(JSON.stringify(data));
  });
  await until(() => client.ready || client.errors.length, `subscribe ${id}`);
  assert.deepEqual(client.errors, []);
  return client;
};
const publish = async (id, round) => {
  const body = JSON.stringify({ name: 'design-model-session.transient', channels: [channel], data: JSON.stringify({
    type: 'cursor', sender: { id, name: `Participant ${id}` }, payload: { x: round, y: 0, z: 0 },
  }) });
  const query = `auth_key=bim-local-key&auth_timestamp=${Math.floor(Date.now() / 1000)}&auth_version=1.0&body_md5=${createHash('md5').update(body).digest('hex')}`;
  sent.set(`${id}:${round}`, performance.now());
  const response = await fetch(`http://127.0.0.1:18079/apps/bim-local-test/events?${query}&auth_signature=${sign(`POST\n/apps/bim-local-test/events\n${query}`)}`, {
    method: 'POST', body, headers: { 'content-type': 'application/json' },
  });
  assert.equal(response.status, 200, await response.text());
};
try {
  for (let id = 1; id <= 10; id++) await connect(id);
  await until(() => clients.every(client => client.members.size === 10), 'presence 10');
  for (let round = 0; round < 10; round++) {
    await Promise.all(Array.from({ length: 10 }, (_, index) => publish(index + 1, round)));
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  await until(() => clients.every(client => client.received.size === 100), '100 events at every participant');
  clients[9].ws.close();
  await until(() => clients.slice(0, 9).every(client => client.members.size === 9), 'member leave');
  const reconnected = await connect(10);
  await until(() => clients.slice(0, 9).every(client => client.members.size === 10), 'member rejoin');
  assert.equal(reconnected.received.size, 0);
  await publish(1, 10);
  await until(() => reconnected.received.size === 1, 'new event after reconnect');
  assert.ok(clients.every(client => client.errors.length === 0));
  latencies.sort((a, b) => a - b);
  const report = { scope: 'standalone Reverb transport only; no Laravel auth, database, IFC or browser', participants: 10, events: sent.size,
    deliveries: latencies.length, p95_ms: latencies[Math.ceil(latencies.length * 0.95) - 1], disconnect_reconnect: 'passed', errors: [] };
  await mkdir('output/reverb', { recursive: true });
  await writeFile('output/reverb/transport-smoke.json', JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
} finally {
  clients.forEach(client => client.ws.close());
}
