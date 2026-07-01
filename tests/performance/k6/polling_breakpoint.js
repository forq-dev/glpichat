import http from 'k6/http';
import { check, sleep } from 'k6';

// Rampeia a taxa de Widget/Poll para descobrir o teto de agentes simultaneos
// que o endpoint aguenta antes de degradar (p95/erros subirem).

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';
const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';

function buildStages() {
  if (__ENV.GLPICHAT_BREAKPOINT_STAGES) {
    return JSON.parse(__ENV.GLPICHAT_BREAKPOINT_STAGES);
  }

  return [
    { target: Number(__ENV.GLPICHAT_BREAKPOINT_STAGE1_RATE || 5), duration: __ENV.GLPICHAT_BREAKPOINT_STAGE1_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_BREAKPOINT_STAGE2_RATE || 20), duration: __ENV.GLPICHAT_BREAKPOINT_STAGE2_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_BREAKPOINT_STAGE3_RATE || 50), duration: __ENV.GLPICHAT_BREAKPOINT_STAGE3_DURATION || '1m' },
    { target: Number(__ENV.GLPICHAT_BREAKPOINT_STAGE4_RATE || 100), duration: __ENV.GLPICHAT_BREAKPOINT_STAGE4_DURATION || '1m' },
  ];
}

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.05'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.widget_poll_breakpoint = {
    executor: 'ramping-arrival-rate',
    exec: 'widgetPoll',
    startRate: Number(__ENV.GLPICHAT_BREAKPOINT_START_RATE || 1),
    timeUnit: '1s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_BREAKPOINT_PRE_ALLOCATED_VUS || 20),
    maxVUs: Number(__ENV.GLPICHAT_BREAKPOINT_MAX_VUS || 300),
    stages: buildStages(),
  };
  thresholds['http_req_duration{scenario:widget_poll_breakpoint}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_BREAKPOINT_P95_MS || 1000)}`,
  ];
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable the breakpoint scenario by setting GLPICHAT_WIDGET_COOKIE + GLPICHAT_WIDGET_CSRF_TOKEN.');
}

export const options = {
  scenarios,
  thresholds,
};

export function widgetPoll() {
  const payload = {
    _glpi_csrf_token: widgetCsrfToken,
    'channels[0][tickets_id]': widgetTicketId,
    'channels[0][room_type]': 'public',
    'channels[0][after_id]': '0',
    'channels[0][after_event_id]': '0',
    'channels[0][is_typing]': '0',
  };

  const response = http.post(
    `${baseUrl}/plugins/glpichat/Widget/Poll`,
    payload,
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': widgetCsrfToken,
        Cookie: widgetCookie,
      },
    }
  );

  check(response, {
    'Widget/Poll status is 200': (res) => res.status === 200,
    'Widget/Poll returns JSON': (res) => String(res.headers['Content-Type'] || '').includes('application/json'),
  });

  sleep(1);
}
