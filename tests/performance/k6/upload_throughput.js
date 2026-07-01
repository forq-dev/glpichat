import http from 'k6/http';
import { check, sleep } from 'k6';

// Uploads concorrentes via Upload (agente) e Guest/Upload (convidado) para
// medir throughput de escrita pesada (disco + DB). Usa um arquivo de teste
// pequeno gerado em memoria (nunca um arquivo de producao real).

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';

const widgetCookie = __ENV.GLPICHAT_WIDGET_COOKIE || '';
const widgetCsrfToken = __ENV.GLPICHAT_WIDGET_CSRF_TOKEN || '';
const widgetTicketId = __ENV.GLPICHAT_WIDGET_TICKET_ID || '1';

const guestCookie = __ENV.GLPICHAT_GUEST_COOKIE || '';

const testFileContent = `glpichat k6 upload_throughput synthetic test file\ngenerated at runtime, not a real attachment\n`.repeat(
  Number(__ENV.GLPICHAT_UPLOAD_FILE_REPEAT || 20)
);

function buildTestFile(vu, iter) {
  return http.file(testFileContent, `k6-upload-test-${vu}-${iter}.txt`, 'text/plain');
}

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.05'],
};

if (widgetCookie !== '' && widgetCsrfToken !== '') {
  scenarios.agent_upload_throughput = {
    executor: 'ramping-vus',
    exec: 'agentUpload',
    startVUs: 0,
    stages: [
      { target: Number(__ENV.GLPICHAT_UPLOAD_STAGE1_VUS || 5), duration: __ENV.GLPICHAT_UPLOAD_STAGE1_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_UPLOAD_STAGE2_VUS || 15), duration: __ENV.GLPICHAT_UPLOAD_STAGE2_DURATION || '2m' },
      { target: Number(__ENV.GLPICHAT_UPLOAD_STAGE3_VUS || 30), duration: __ENV.GLPICHAT_UPLOAD_STAGE3_DURATION || '2m' },
      { target: 0, duration: '30s' },
    ],
  };
  thresholds['http_req_duration{scenario:agent_upload_throughput}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_UPLOAD_P95_MS || 3000)}`,
  ];
}

if (guestCookie !== '') {
  scenarios.guest_upload_throughput = {
    executor: 'ramping-vus',
    exec: 'guestUpload',
    startVUs: 0,
    stages: [
      { target: Number(__ENV.GLPICHAT_GUEST_UPLOAD_STAGE1_VUS || 3), duration: __ENV.GLPICHAT_GUEST_UPLOAD_STAGE1_DURATION || '1m' },
      { target: Number(__ENV.GLPICHAT_GUEST_UPLOAD_STAGE2_VUS || 8), duration: __ENV.GLPICHAT_GUEST_UPLOAD_STAGE2_DURATION || '2m' },
      { target: 0, duration: '30s' },
    ],
  };
  // Guest/Upload tem throttle de ~2s por participante; nao aplicamos
  // threshold rigido de erro para nao penalizar 429 esperados.
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable at least one scenario by setting GLPICHAT_WIDGET_COOKIE + GLPICHAT_WIDGET_CSRF_TOKEN or GLPICHAT_GUEST_COOKIE.');
}

export const options = {
  scenarios,
  thresholds,
};

export function agentUpload() {
  const file = buildTestFile(__VU, __ITER);

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

  sleep(1);
}

export function guestUpload() {
  const file = buildTestFile(__VU, __ITER);

  const response = http.post(
    `${baseUrl}/plugins/glpichat/Guest/Upload`,
    {
      file,
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
    'Guest/Upload status is 200 or throttled (429)': (res) => res.status === 200 || res.status === 429,
  });

  sleep(2);
}
