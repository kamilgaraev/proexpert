import http from 'k6/http';
import { check, group, sleep } from 'k6';
import exec from 'k6/execution';
import { Trend } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const PROFILE = (__ENV.PROFILE || 'smoke').toLowerCase();
const LOGIN_PATH = __ENV.LOGIN_PATH || '/api/v1/admin/auth/login';
const PASSWORD = __ENV.LOAD_TEST_PASSWORD || 'LoadTest123!';
const ACCOUNT_LABELS = splitList(__ENV.ACCOUNT_LABELS || 'owner');
const REQUEST_TIMEOUT = __ENV.REQUEST_TIMEOUT || '30s';
const FAIL_RATE = __ENV.FAIL_RATE || '0.02';
const P95_MS = __ENV.P95_MS || '1000';
const P99_MS = __ENV.P99_MS || '2500';
const CHECK_RATE = __ENV.CHECK_RATE || '0.98';
const THINK_TIME_MIN = parseFloat(__ENV.THINK_TIME_MIN || '1');
const THINK_TIME_MAX = parseFloat(__ENV.THINK_TIME_MAX || '3');
const LOG_FAILURES = parseBool(__ENV.LOG_FAILURES, true);
const INCLUDE_SITE_REQUEST_LIST = parseBool(__ENV.INCLUDE_SITE_REQUEST_LIST, false);
const INCLUDE_PROJECT_CONTRACT_LIST = parseBool(__ENV.INCLUDE_PROJECT_CONTRACT_LIST, false);
const ALLOWED_STATUSES = parseStatusList(__ENV.ALLOWED_STATUSES || '200,204,304');
const SUMMARY_JSON = __ENV.K6_SUMMARY_JSON || '';
const ENDPOINT_TRENDS = buildEndpointTrends();

if (!BASE_URL) {
  throw new Error('BASE_URL is required, for example: https://example.com');
}

export const options = {
  scenarios: {
    admin_readonly: {
      executor: 'ramping-vus',
      gracefulRampDown: '30s',
      stages: profileStages(PROFILE),
    },
  },
  thresholds: {
    http_req_failed: [`rate<${FAIL_RATE}`],
    http_req_duration: [`p(95)<${P95_MS}`, `p(99)<${P99_MS}`],
    checks: [`rate>${CHECK_RATE}`],
  },
  noConnectionReuse: false,
  userAgent: `prohelper-k6/${PROFILE}`,
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
};

const DEFAULT_ACCOUNTS = buildDefaultAccounts();

export function setup() {
  const accounts = DEFAULT_ACCOUNTS.filter((account) => ACCOUNT_LABELS.includes(account.label));

  if (accounts.length === 0) {
    throw new Error(`No accounts selected. ACCOUNT_LABELS=${ACCOUNT_LABELS.join(',')}`);
  }

  const sessions = [];

  for (const account of accounts) {
    const token = login(account);

    if (!token) {
      continue;
    }

    sessions.push({
      email: account.email,
      organization: account.organization,
      label: account.label,
      token,
      projects: discoverIds(token, 'setup_projects', '/api/v1/admin/projects?per_page=50'),
      warehouses: discoverIds(token, 'setup_warehouses', '/api/v1/admin/warehouses'),
      siteRequests: INCLUDE_SITE_REQUEST_LIST
        ? discoverIds(token, 'setup_site_requests', '/api/v1/admin/site-requests?per_page=50')
        : [],
      paymentDocuments: discoverIds(token, 'setup_payment_documents', '/api/v1/admin/payments/documents?per_page=50'),
    });
  }

  if (sessions.length === 0) {
    throw new Error('Could not authenticate any load-test account.');
  }

  for (const session of sessions) {
    session.schedules = discoverSchedules(session.token, session.projects.slice(0, 3));
  }

  console.log(`Authenticated ${sessions.length} load-test accounts for profile ${PROFILE}.`);

  return { sessions };
}

export default function (data) {
  const session = pickSession(data.sessions);
  const projectId = randomItem(session.projects);

  group('admin core', () => {
    const requests = [
      ['auth_me', '/api/v1/admin/auth/me'],
      ['projects_index', '/api/v1/admin/projects?per_page=15'],
    ];

    if (projectId) {
      requests.push(
        ['dashboard', `/api/v1/admin/dashboard?project_id=${projectId}`],
        ['dashboard_summary', `/api/v1/admin/dashboard/summary?project_id=${projectId}`]
      );
    }

    batchGet(session, requests);
  });

  group('project workspace', () => {
    if (!projectId) {
      return;
    }

    const requests = [
      ['project_show', `/api/v1/admin/projects/${projectId}`],
      ['project_context', `/api/v1/admin/projects/${projectId}/context`],
      ['project_permissions', `/api/v1/admin/projects/${projectId}/permissions`],
      ['project_statistics', `/api/v1/admin/projects/${projectId}/statistics`],
      ['project_materials', `/api/v1/admin/projects/${projectId}/materials?per_page=15`],
      ['project_schedules', `/api/v1/admin/projects/${projectId}/schedules`],
      ['project_material_analytics', `/api/v1/admin/projects/${projectId}/analytics/materials`],
    ];

    if (INCLUDE_PROJECT_CONTRACT_LIST) {
      requests.push(['project_contracts', `/api/v1/admin/projects/${projectId}/contracts?per_page=15`]);
    }

    batchGet(session, requests);

    const schedule = randomItem(session.schedules.filter((item) => item.projectId === projectId));

    if (schedule) {
      get(session, 'schedule_tasks', `/api/v1/admin/projects/${projectId}/schedules/${schedule.id}/tasks`);
    }
  });

  group('operations modules', () => {
    const requests = [
      ['site_requests_statistics', '/api/v1/admin/site-requests/dashboard/statistics'],
      ['warehouses_index', '/api/v1/admin/warehouses'],
      ['payments_dashboard', '/api/v1/admin/payments/dashboard'],
      ['payments_documents', '/api/v1/admin/payments/documents?per_page=15'],
      ['payments_documents_statistics', '/api/v1/admin/payments/documents/statistics'],
    ];

    if (INCLUDE_SITE_REQUEST_LIST) {
      requests.push(['site_requests_index', '/api/v1/admin/site-requests?per_page=15']);
    }

    batchGet(session, requests);

    const warehouseId = randomItem(session.warehouses);

    if (warehouseId) {
      batchGet(session, [
        ['warehouse_show', `/api/v1/admin/warehouses/${warehouseId}`],
        ['warehouse_balances', `/api/v1/admin/warehouses/${warehouseId}/balances`],
      ]);
    }

    const siteRequestId = INCLUDE_SITE_REQUEST_LIST ? randomItem(session.siteRequests) : null;

    if (siteRequestId) {
      get(session, 'site_request_show', `/api/v1/admin/site-requests/${siteRequestId}`);
    }

    const paymentDocumentId = randomItem(session.paymentDocuments);

    if (paymentDocumentId) {
      get(session, 'payment_document_show', `/api/v1/admin/payments/documents/${paymentDocumentId}`);
    }
  });

  sleep(randomBetween(THINK_TIME_MIN, THINK_TIME_MAX));
}

export function handleSummary(data) {
  const outputs = {
    stdout: buildSummary(data),
  };

  if (SUMMARY_JSON) {
    outputs[SUMMARY_JSON] = JSON.stringify(data, null, 2);
  }

  return outputs;
}

function login(account) {
  const response = http.post(
    fullUrl(LOGIN_PATH),
    JSON.stringify({
      email: account.email,
      password: PASSWORD,
    }),
    {
      headers: jsonHeaders(),
      timeout: REQUEST_TIMEOUT,
      tags: {
        endpoint: 'auth_login',
        account: account.label,
        organization: account.organization,
      },
    }
  );

  const payload = parseJson(response);
  const token = extractToken(payload);

  recordEndpoint('auth_login', response);

  if (response.status !== 200 || !token) {
    logFailure('auth_login', LOGIN_PATH, response);

    return null;
  }

  return token;
}

function discoverIds(token, name, path) {
  const response = http.get(fullUrl(path), {
    headers: authHeaders(token),
    timeout: REQUEST_TIMEOUT,
    tags: { endpoint: name },
  });

  recordEndpoint(name, response);

  if (response.status !== 200) {
    logFailure(name, path, response);

    return [];
  }

  return extractItems(parseJson(response))
    .map((item) => extractId(item))
    .filter((id) => id !== null);
}

function discoverSchedules(token, projectIds) {
  const schedules = [];

  for (const projectId of projectIds) {
    const response = http.get(fullUrl(`/api/v1/admin/projects/${projectId}/schedules`), {
      headers: authHeaders(token),
      timeout: REQUEST_TIMEOUT,
      tags: { endpoint: 'setup_project_schedules' },
    });

    recordEndpoint('setup_project_schedules', response);

    if (response.status !== 200) {
      continue;
    }

    const ids = extractItems(parseJson(response))
      .map((item) => extractId(item))
      .filter((id) => id !== null);

    for (const id of ids) {
      schedules.push({ id, projectId });
    }
  }

  return schedules;
}

function batchGet(session, requests) {
  const responses = http.batch(
    requests.map(([name, path]) => [
      'GET',
      fullUrl(path),
      null,
      {
        headers: authHeaders(session.token),
        timeout: REQUEST_TIMEOUT,
        tags: {
          endpoint: name,
          account: session.label,
          organization: session.organization,
        },
      },
    ])
  );

  responses.forEach((response, index) => {
    const [name, path] = requests[index];

    recordEndpoint(name, response);
    verifyResponse(name, path, response);
  });

  return responses;
}

function get(session, name, path) {
  const response = http.get(fullUrl(path), {
    headers: authHeaders(session.token),
    timeout: REQUEST_TIMEOUT,
    tags: {
      endpoint: name,
      account: session.label,
      organization: session.organization,
    },
  });

  recordEndpoint(name, response);
  verifyResponse(name, path, response);

  return response;
}

function verifyResponse(name, path, response) {
  const checks = {};
  checks[`${name} status is allowed`] = (item) => ALLOWED_STATUSES.includes(item.status);

  const ok = check(response, checks);

  if (!ok) {
    logFailure(name, path, response);
  }
}

function authHeaders(token) {
  return {
    ...jsonHeaders(),
    Authorization: `Bearer ${token}`,
  };
}

function jsonHeaders() {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Load-Test': 'prohelper-k6-readonly',
  };
}

function fullUrl(path) {
  return `${BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
}

function parseJson(response) {
  try {
    return response.json();
  } catch (error) {
    return null;
  }
}

function extractToken(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  const data = payload.data || payload;

  return data.token
    || data.access_token
    || data.jwt
    || data.auth?.token
    || data.authorization?.token
    || null;
}

function extractItems(payload) {
  if (!payload || typeof payload !== 'object') {
    return [];
  }

  const data = payload.data ?? payload;

  if (Array.isArray(data)) {
    return data;
  }

  if (Array.isArray(data.data)) {
    return data.data;
  }

  if (Array.isArray(data.items)) {
    return data.items;
  }

  if (Array.isArray(data.results)) {
    return data.results;
  }

  if (data.data && Array.isArray(data.data.data)) {
    return data.data.data;
  }

  return [];
}

function extractId(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  return item.id
    ?? item.project_id
    ?? item.warehouse_id
    ?? item.schedule_id
    ?? item.document_id
    ?? null;
}

function pickSession(sessions) {
  const vuId = exec.vu.idInTest || 1;

  return sessions[(vuId - 1) % sessions.length];
}

function randomItem(items) {
  if (!items || items.length === 0) {
    return null;
  }

  return items[Math.floor(Math.random() * items.length)];
}

function randomBetween(min, max) {
  if (max <= min) {
    return min;
  }

  return min + Math.random() * (max - min);
}

function buildDefaultAccounts() {
  const labels = [
    ['owner', 'owner'],
    ['admin', 'admin'],
    ['pm', 'pm'],
    ['accountant', 'accountant'],
  ];

  const accounts = [];

  for (let org = 1; org <= 6; org += 1) {
    const organization = `LT${String(org).padStart(2, '0')}`;

    for (const [label, suffix] of labels) {
      accounts.push({
        label,
        organization,
        email: `loadtest-org${String(org).padStart(2, '0')}-${suffix}@prohelper.test`,
      });
    }
  }

  return accounts;
}

function buildEndpointTrends() {
  const names = [
    'auth_login',
    'setup_projects',
    'setup_warehouses',
    'setup_site_requests',
    'setup_payment_documents',
    'setup_project_schedules',
    'auth_me',
    'dashboard',
    'dashboard_summary',
    'projects_index',
    'project_show',
    'project_context',
    'project_permissions',
    'project_statistics',
    'project_materials',
    'project_contracts',
    'project_schedules',
    'project_material_analytics',
    'schedule_tasks',
    'site_requests_index',
    'site_requests_statistics',
    'warehouses_index',
    'payments_dashboard',
    'payments_documents',
    'payments_documents_statistics',
    'warehouse_show',
    'warehouse_balances',
    'site_request_show',
    'payment_document_show',
  ];

  const trends = {};

  for (const name of names) {
    trends[name] = new Trend(`endpoint_${name}`, true);
  }

  return trends;
}

function recordEndpoint(name, response) {
  const trend = ENDPOINT_TRENDS[name];

  if (!trend || !response || !response.timings) {
    return;
  }

  trend.add(response.timings.duration);
}

function profileStages(profile) {
  const profiles = {
    smoke: [
      { duration: '30s', target: 1 },
      { duration: '1m', target: 2 },
      { duration: '30s', target: 0 },
    ],
    baseline: [
      { duration: '2m', target: 5 },
      { duration: '5m', target: 10 },
      { duration: '2m', target: 10 },
      { duration: '2m', target: 0 },
    ],
    peak: [
      { duration: '2m', target: 10 },
      { duration: '5m', target: 20 },
      { duration: '3m', target: 30 },
      { duration: '2m', target: 0 },
    ],
    stress: [
      { duration: '3m', target: 25 },
      { duration: '5m', target: 50 },
      { duration: '5m', target: 75 },
      { duration: '3m', target: 0 },
    ],
    soak: [
      { duration: '5m', target: 15 },
      { duration: '55m', target: 15 },
      { duration: '5m', target: 0 },
    ],
  };

  if (!profiles[profile]) {
    throw new Error(`Unknown PROFILE=${profile}. Allowed values: ${Object.keys(profiles).join(', ')}`);
  }

  return profiles[profile];
}

function splitList(value) {
  return value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item.length > 0);
}

function parseBool(value, defaultValue) {
  if (value === undefined || value === null || value === '') {
    return defaultValue;
  }

  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function parseStatusList(value) {
  return splitList(value)
    .map((item) => parseInt(item, 10))
    .filter((item) => !Number.isNaN(item));
}

function logFailure(name, path, response) {
  if (!LOG_FAILURES) {
    return;
  }

  const body = String(response.body || '').slice(0, 500).replace(/\s+/g, ' ');

  console.warn(`${name} failed: status=${response.status} url=${path} body=${body}`);
}

function buildSummary(data) {
  const duration = metric(data, 'http_req_duration');
  const failed = metric(data, 'http_req_failed');
  const checks = metric(data, 'checks');
  const requests = data.metrics.http_reqs?.values?.count || 0;
  const iterations = data.metrics.iterations?.values?.count || 0;

  return [
    '',
    'ProHelper k6 summary',
    `profile: ${PROFILE}`,
    `requests: ${formatNumber(requests)}`,
    `iterations: ${formatNumber(iterations)}`,
    `http_req_failed.rate: ${formatPercent(failed.rate)}`,
    `checks.rate: ${formatPercent(checks.rate)}`,
    `http_req_duration.avg: ${formatMs(duration.avg)}`,
    `http_req_duration.p95: ${formatMs(duration['p(95)'])}`,
    `http_req_duration.p99: ${formatMs(duration['p(99)'])}`,
    '',
    ...slowEndpointLines(data),
    '',
  ].join('\n');
}

function slowEndpointLines(data) {
  const rows = [];

  for (const name in data.metrics) {
    if (!name.startsWith('endpoint_')) {
      continue;
    }

    const values = data.metrics[name]?.values || {};

    rows.push({
      name: name.replace(/^endpoint_/, ''),
      avg: values.avg,
      p95: values['p(95)'],
      p99: values['p(99)'],
      max: values.max,
    });
  }

  rows.sort((left, right) => (right.p95 || 0) - (left.p95 || 0));

  const top = rows.filter((row) => row.p95 !== undefined).slice(0, 8);

  if (top.length === 0) {
    return ['slow endpoints: n/a'];
  }

  return [
    'slow endpoints by p95:',
    ...top.map((row) => `${row.name}: avg=${formatMs(row.avg)}, p95=${formatMs(row.p95)}, p99=${formatMs(row.p99)}, max=${formatMs(row.max)}`),
  ];
}

function metric(data, name) {
  return data.metrics[name]?.values || {};
}

function formatPercent(value) {
  if (value === undefined) {
    return 'n/a';
  }

  return `${(value * 100).toFixed(2)}%`;
}

function formatMs(value) {
  if (value === undefined) {
    return 'n/a';
  }

  return `${value.toFixed(0)} ms`;
}

function formatNumber(value) {
  return String(Math.round(Number(value || 0)));
}
