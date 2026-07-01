import http from 'k6/http';
import { check, sleep } from 'k6';

// Carga moderada e constante (poll + send mesclados) por duracao longa
// configuravel, para detectar degradacao ao longo do tempo (memory leak,
// conexoes presas, crescimento de latencia, etc).

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';
const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';
const guestCookie = __ENV.GLPICHAT_GUEST_COOKIE || '';

const soakDuration = __ENV.GLPICHAT_SOAK_DURATION || '30m';

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.01'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.soak_widget_poll = {
    executor: 'constant-arrival-rate',
    exec: 'widgetPoll',
    rate: Number(__ENV.GLPICHAT_SOAK_WIDGET_RATE || 5),
    timeUnit: '1s',
    duration: soakDuration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_SOAK_WIDGET_PRE_ALLOCATED_VUS || 5),
    maxVUs: Number(__ENV.GLPICHAT_SOAK_WIDGET_MAX_VUS || 30),
  };

  scenarios.soak_agent_send = {
    executor: 'constant-arrival-rate',
    exec: 'agentSend',
    rate: Number(__ENV.GLPICHAT_SOAK_SEND_RATE || 1),
    timeUnit: '1s',
    duration: soakDuration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_SOAK_SEND_PRE_ALLOCATED_VUS || 2),
    maxVUs: Number(__ENV.GLPICHAT_SOAK_SEND_MAX_VUS || 20),
  };

  thresholds['http_req_duration{scenario:soak_widget_poll}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_SOAK_WIDGET_P95_MS || 800)}`,
  ];
  thresholds['http_req_duration{scenario:soak_agent_send}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_SOAK_SEND_P95_MS || 1200)}`,
  ];
}

if (guestCookie !== '') {
  scenarios.soak_guest_sync = {
    executor: 'constant-arrival-rate',
    exec: 'guestSync',
    rate: Number(__ENV.GLPICHAT_SOAK_GUEST_RATE || 5),
    timeUnit: '1s',
    duration: soakDuration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_SOAK_GUEST_PRE_ALLOCATED_VUS || 5),
    maxVUs: Number(__ENV.GLPICHAT_SOAK_GUEST_MAX_VUS || 30),
  };
  thresholds['http_req_duration{scenario:soak_guest_sync}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_SOAK_GUEST_P95_MS || 600)}`,
  ];
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable at least one scenario by setting GLPICHAT_WIDGET_COOKIE + GLPICHAT_WIDGET_CSRF_TOKEN or GLPICHAT_GUEST_COOKIE.');
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
  });

  sleep(1);
}

export function agentSend() {
  const payload = {
    _glpi_csrf_token: widgetCsrfToken,
    tickets_id: widgetTicketId,
    room_type: 'public',
    content: `soak load test message at ${Date.now()}`,
  };

  const response = http.post(
    `${baseUrl}/plugins/glpichat/Send`,
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
    'Send status is 200': (res) => res.status === 200,
  });

  sleep(5);
}

export function guestSync() {
  const response = http.get(
    `${baseUrl}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0`,
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Cookie: guestCookie,
      },
    }
  );

  check(response, {
    'Guest/Sync status is 200': (res) => res.status === 200,
  });

  sleep(1);
}
