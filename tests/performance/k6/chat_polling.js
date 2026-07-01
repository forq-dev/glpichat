import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';
const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';
const guestCookie = __ENV.GLPICHAT_GUEST_COOKIE || '';
const guestLastMessageId = __ENV.GLPICHAT_GUEST_LAST_MESSAGE_ID || '0';
const guestLastEventId = __ENV.GLPICHAT_GUEST_LAST_EVENT_ID || '0';

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.01'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.widget_poll = {
    executor: 'constant-arrival-rate',
    exec: 'widgetPoll',
    rate: Number(__ENV.GLPICHAT_WIDGET_RATE || 8),
    timeUnit: '1s',
    duration: __ENV.GLPICHAT_WIDGET_DURATION || '30s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_WIDGET_PRE_ALLOCATED_VUS || 4),
    maxVUs: Number(__ENV.GLPICHAT_WIDGET_MAX_VUS || 20),
  };
  thresholds['http_req_duration{scenario:widget_poll}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_WIDGET_P95_MS || 800)}`,
  ];
}

if (guestCookie !== '') {
  scenarios.guest_sync = {
    executor: 'constant-arrival-rate',
    exec: 'guestSync',
    rate: Number(__ENV.GLPICHAT_GUEST_RATE || 8),
    timeUnit: '1s',
    duration: __ENV.GLPICHAT_GUEST_DURATION || '30s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_GUEST_PRE_ALLOCATED_VUS || 4),
    maxVUs: Number(__ENV.GLPICHAT_GUEST_MAX_VUS || 20),
  };
  thresholds['http_req_duration{scenario:guest_sync}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_GUEST_P95_MS || 600)}`,
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
    'Widget/Poll returns JSON': (res) => String(res.headers['Content-Type'] || '').includes('application/json'),
  });

  sleep(1);
}

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
