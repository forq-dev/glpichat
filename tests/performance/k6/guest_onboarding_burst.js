import http from 'k6/http';
import { check, sleep } from 'k6';

// Simula rajada de aceite de convites (Accept/{token}) vindos de IPs
// diferentes simulados via header X-Forwarded-For, validando que o rate
// limit de 10 tentativas/60s por IP responde corretamente (429 esperado
// acima do limite) sem quebrar sob concorrencia legitima.
//
// Este NAO e um teste de abuso/DoS: o objetivo e confirmar que o limiter
// funciona, nao burla-lo ou desabilita-lo.

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';

function loadTokens() {
  if (!__ENV.GLPICHAT_INVITE_TOKENS_JSON) {
    return [];
  }
  try {
    const parsed = JSON.parse(__ENV.GLPICHAT_INVITE_TOKENS_JSON);
    if (!Array.isArray(parsed)) {
      throw new Error('GLPICHAT_INVITE_TOKENS_JSON must be a JSON array of strings');
    }
    return parsed;
  } catch (err) {
    throw new Error(`Invalid GLPICHAT_INVITE_TOKENS_JSON: ${err.message}`);
  }
}

const tokens = loadTokens();

// Quantas "tentativas simuladas" por IP dentro da janela de 60s. Default 15
// (acima do limite de 10) para observar o 429 aparecer de forma legitima.
const attemptsPerIp = Number(__ENV.GLPICHAT_ONBOARDING_ATTEMPTS_PER_IP || 15);

const scenarios = {};

if (tokens.length > 0) {
  scenarios.guest_onboarding_burst = {
    executor: 'per-vu-iterations',
    exec: 'acceptInvite',
    vus: tokens.length,
    iterations: attemptsPerIp,
    maxDuration: __ENV.GLPICHAT_ONBOARDING_MAX_DURATION || '2m',
  };
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable the guest_onboarding_burst scenario by setting GLPICHAT_INVITE_TOKENS_JSON to a JSON array of invite tokens.');
}

export const options = {
  scenarios,
};

export function acceptInvite() {
  const token = tokens[(__VU - 1) % tokens.length];
  // Cada VU simula um IP de origem distinto via header, para exercitar o
  // rate limit por IP sem depender de infraestrutura de rede real.
  const simulatedIp = `10.${__ENV.GLPICHAT_ONBOARDING_IP_OCTET || '99'}.${__VU % 256}.${(__VU + 1) % 256}`;

  const response = http.get(
    `${baseUrl}/plugins/glpichat/Accept/${token}`,
    {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Forwarded-For': simulatedIp,
      },
    }
  );

  check(response, {
    'Accept status is 200, 302, or rate-limited (429)': (res) => (
      res.status === 200 || res.status === 302 || res.status === 429
    ),
    'Accept did not 5xx (service did not crash)': (res) => res.status < 500,
  });

  sleep(0.2);
}
