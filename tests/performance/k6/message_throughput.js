import http from 'k6/http';
import { check, sleep } from 'k6';

// Rampeia o envio de mensagens (Send para agente, Guest/Send para convidado)
// para medir mensagens/s sustentavel antes de degradar. Guest/Send tem
// throttle de ~2s por participante: o objetivo aqui e medir o comportamento
// do throttle sob concorrencia (esperamos ver 429/erros esperados acima da
// taxa permitida), nunca tentar contorna-lo.

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';

const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';

const guestCookie = __ENV.GLPICHAT_GUEST_COOKIE || '';

function buildStages(prefix, defaults) {
  const envKey = `GLPICHAT_${prefix}_STAGES`;
  if (__ENV[envKey]) {
    return JSON.parse(__ENV[envKey]);
  }
  return defaults;
}

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.05'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.agent_send_throughput = {
    executor: 'ramping-arrival-rate',
    exec: 'agentSend',
    startRate: Number(__ENV.GLPICHAT_SEND_START_RATE || 1),
    timeUnit: '1s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_SEND_PRE_ALLOCATED_VUS || 10),
    maxVUs: Number(__ENV.GLPICHAT_SEND_MAX_VUS || 150),
    stages: buildStages('SEND', [
      { target: Number(__ENV.GLPICHAT_SEND_STAGE1_RATE || 2), duration: __ENV.GLPICHAT_SEND_STAGE1_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_SEND_STAGE2_RATE || 5), duration: __ENV.GLPICHAT_SEND_STAGE2_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_SEND_STAGE3_RATE || 10), duration: __ENV.GLPICHAT_SEND_STAGE3_DURATION || '1m' },
    ]),
  };
  thresholds['http_req_duration{scenario:agent_send_throughput}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_SEND_P95_MS || 1200)}`,
  ];
}

if (guestCookie !== '') {
  scenarios.guest_send_throughput = {
    executor: 'ramping-arrival-rate',
    exec: 'guestSend',
    startRate: Number(__ENV.GLPICHAT_GUEST_SEND_START_RATE || 1),
    timeUnit: '1s',
    preAllocatedVUs: Number(__ENV.GLPICHAT_GUEST_SEND_PRE_ALLOCATED_VUS || 10),
    maxVUs: Number(__ENV.GLPICHAT_GUEST_SEND_MAX_VUS || 150),
    stages: buildStages('GUEST_SEND', [
      { target: Number(__ENV.GLPICHAT_GUEST_SEND_STAGE1_RATE || 1), duration: __ENV.GLPICHAT_GUEST_SEND_STAGE1_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_GUEST_SEND_STAGE2_RATE || 3), duration: __ENV.GLPICHAT_GUEST_SEND_STAGE2_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_GUEST_SEND_STAGE3_RATE || 6), duration: __ENV.GLPICHAT_GUEST_SEND_STAGE3_DURATION || '1m' },
    ]),
  };
  // Nao aplicamos threshold de erro rígido aqui: acima de ~0.5 msg/s por
  // participante o throttle de 2s deve responder 429 legitimamente.
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable at least one scenario by setting GLPICHAT_WIDGET_COOKIE + GLPICHAT_WIDGET_CSRF_TOKEN or GLPICHAT_GUEST_COOKIE.');
}

export const options = {
  scenarios,
  thresholds,
};

export function agentSend() {
  const payload = {
    _glpi_csrf_token: widgetCsrfToken,
    tickets_id: widgetTicketId,
    room_type: 'public',
    content: `message_throughput load test message at ${Date.now()}`,
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
    'Send returns JSON': (res) => String(res.headers['Content-Type'] || '').includes('application/json'),
  });
}

export function guestSend() {
  const response = http.post(
    `${baseUrl}/plugins/glpichat/Guest/Send`,
    {
      content: `message_throughput guest load test message at ${Date.now()}`,
    },
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Cookie: guestCookie,
      },
    }
  );

  check(response, {
    // 429 e a resposta esperada quando o throttle de ~2s por participante e acionado.
    'Guest/Send status is 200 or throttled (429)': (res) => res.status === 200 || res.status === 429,
  });
}
