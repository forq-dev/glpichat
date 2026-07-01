import http from 'k6/http';
import { check, sleep } from 'k6';

// Rampeia a taxa de Guest/Sync para descobrir o teto de convidados simultaneos
// que o endpoint aguenta antes de degradar (p95/erros subirem).

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';
const guestCookie = __ENV.GLPICHAT_GUEST_COOKIE || '';
const guestLastMessageId = __ENV.GLPICHAT_GUEST_LAST_MESSAGE_ID || '0';
const guestLastEventId = __ENV.GLPICHAT_GUEST_LAST_EVENT_ID || '0';

function buildStages() {
  if (__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGES) {
    return JSON.parse(__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGES);
  }

  return [
    { target: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE1_RATE || 5), duration: __ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE1_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE2_RATE || 20), duration: __ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE2_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE3_RATE || 50), duration: __ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE3_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE4_RATE || 100), duration: __ENV.GLPICHAT_GUEST_BREAKPOINT_STAGE4_DURATION || '1m' },
  ];
}

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.05'],
};

if (guestCookie !== '') {
  scenarios.guest_sync_breakpoint = {
    executor: 'ramping-arrival-rate',
    exec: 'guestSync',
    startRate: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_START_RATE || 1),
    timeUnit: '1s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_PRE_ALLOCATED_VUS || 20),
    maxVUs: Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_MAX_VUS || 300),
    stages: buildStages(),
  };
  thresholds['http_req_duration{scenario:guest_sync_breakpoint}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_GUEST_BREAKPOINT_P95_MS || 800)}`,
  ];
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable the breakpoint scenario by setting GLPICHAT_GUEST_COOKIE.');
}

export const options = {
  scenarios,
  thresholds,
};

export function guestSync() {
  const response = http.get(
    `${baseUrl}/plugins/glpichat/Guest/Sync?last_message_id=${guestLastMessageId}&last_event_id=${guestLastEventId}`,
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Cookie: guestCookie,
      },
    }
  );

  check(response, {
    'Guest/Sync status is 200': (res) => res.status === 200,
    'Guest/Sync returns JSON': (res) => String(res.headers['Content-Type'] || '').includes('application/json'),
  });

  sleep(1);
}
