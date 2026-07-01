import http from 'k6/http';
import { check, sleep } from 'k6';

// Combina uma proporcao realista de trafego em paralelo:
// ~80% Widget/Poll, ~15% Send, ~4% Widget/MarkRead, ~1% Upload.
// Cada categoria roda como seu proprio scenario com constant-arrival-rate,
// com a rate proporcional a taxa total configurada.

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';
const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';

const testFileContent = 'glpichat k6 realistic_mix synthetic upload test file\n'.repeat(20);

// Taxa total de requisicoes/s do mix realista. As proporcoes abaixo sao
// aplicadas sobre este total.
const totalRate = Number(__ENV.GLPICHAT_MIX_TOTAL_RATE || 20);
const duration = __ENV.GLPICHAT_MIX_DURATION || '5m';

const pollRatio = Number(__ENV.GLPICHAT_MIX_POLL_RATIO || 0.80);
const sendRatio = Number(__ENV.GLPICHAT_MIX_SEND_RATIO || 0.15);
const markReadRatio = Number(__ENV.GLPICHAT_MIX_MARKREAD_RATIO || 0.04);
const uploadRatio = Number(__ENV.GLPICHAT_MIX_UPLOAD_RATIO || 0.01);

function rateFor(ratio) {
  // Rate minima de 1 req a cada 10s para nao zerar completamente um cenario
  // de baixo volume quando o total configurado for pequeno.
  return Math.max(Number((totalRate * ratio).toFixed(2)), 0.1);
}

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.02'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.mix_poll = {
    executor: 'constant-arrival-rate',
    exec: 'mixPoll',
    rate: rateFor(pollRatio),
    timeUnit: '1s',
    duration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_MIX_POLL_PRE_ALLOCATED_VUS || 10),
    maxVUs: Number(__ENV.GLPICHAT_MIX_POLL_MAX_VUS || 100),
  };

  scenarios.mix_send = {
    executor: 'constant-arrival-rate',
    exec: 'mixSend',
    rate: rateFor(sendRatio),
    timeUnit: '1s',
    duration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_MIX_SEND_PRE_ALLOCATED_VUS || 5),
    maxVUs: Number(__ENV.GLPICHAT_MIX_SEND_MAX_VUS || 50),
  };

  scenarios.mix_markread = {
    executor: 'constant-arrival-rate',
    exec: 'mixMarkRead',
    rate: rateFor(markReadRatio),
    timeUnit: '1s',
    duration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_MIX_MARKREAD_PRE_ALLOCATED_VUS || 2),
    maxVUs: Number(__ENV.GLPICHAT_MIX_MARKREAD_MAX_VUS || 20),
  };

  scenarios.mix_upload = {
    executor: 'constant-arrival-rate',
    exec: 'mixUpload',
    rate: rateFor(uploadRatio),
    timeUnit: '1s',
    duration,
    preAllocatedVUs: Number(__ENV.GLPICHAT_MIX_UPLOAD_PRE_ALLOCATED_VUS || 2),
    maxVUs: Number(__ENV.GLPICHAT_MIX_UPLOAD_MAX_VUS || 20),
  };

  thresholds['http_req_duration{scenario:mix_poll}'] = [`p(95)<${Number(__ENV.GLPICHAT_MIX_POLL_P95_MS || 800)}`];
  thresholds['http_req_duration{scenario:mix_send}'] = [`p(95)<${Number(__ENV.GLPICHAT_MIX_SEND_P95_MS || 1200)}`];
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable the realistic_mix scenario by setting GLPICHAT_WIDGET_COOKIE + GLPICHAT_WIDGET_CSRF_TOKEN.');
}

export const options = {
  scenarios,
  thresholds,
};

export function mixPoll() {
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
}

export function mixSend() {
  const payload = {
    _glpi_csrf_token: widgetCsrfToken,
    tickets_id: widgetTicketId,
    room_type: 'public',
    content: `realistic_mix load test message at ${Date.now()}`,
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
}

export function mixMarkRead() {
  const payload = {
    _glpi_csrf_token: widgetCsrfToken,
    tickets_id: widgetTicketId,
    room_type: 'public',
  };

  const response = http.post(
    `${baseUrl}/plugins/glpichat/Widget/MarkRead`,
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
    'Widget/MarkRead status is 200': (res) => res.status === 200,
  });
}

export function mixUpload() {
  const file = http.file(testFileContent, `k6-realistic-mix-${__VU}-${__ITER}.txt`, 'text/plain');

  const response = http.post(
    `${baseUrl}/plugins/glpichat/Upload`,
    {
      _glpi_csrf_token: widgetCsrfToken,
      tickets_id: widgetTicketId,
      room_type: 'public',
      file,
    },
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': widgetCsrfToken,
        Cookie: widgetCookie,
      },
    }
  );

  check(response, {
    'Upload status is 200': (res) => res.status === 200,
  });
}
