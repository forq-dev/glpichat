import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

// Simula N salas (tickets) ativas simultaneamente. Cada sala tem um agente
// fazendo poll e um guest fazendo poll+send, para medir "quantos chats
// simultaneos" o sistema aguenta.
//
// Formato de GLPICHAT_ROOMS_JSON (array JSON), um objeto por sala:
// [
//   {
//     "ticket_id": "123",
//     "widget_cookie": "glpi_...",
//     "widget_csrf": "abc...",
//     "guest_cookie": "glpichat_guest=..."
//   },
//   ...
// ]

const baseUrl = __ENV.GLPICHAT_BASE_URL || 'http://localhost:8080';

function loadRooms() {
  if (!__ENV.GLPICHAT_ROOMS_JSON) {
    return [];
  }

  try {
    const parsed = JSON.parse(__ENV.GLPICHAT_ROOMS_JSON);
    if (!Array.isArray(parsed)) {
      throw new Error('GLPICHAT_ROOMS_JSON must be a JSON array');
    }
    return parsed;
  } catch (err) {
    throw new Error(`Invalid GLPICHAT_ROOMS_JSON: ${err.message}`);
  }
}

const rooms = loadRooms();

const roomsCounter = new Counter('glpichat_rooms_active');

const scenarios = {};
const thresholds = {
  http_req_failed: ['rate<0.02'],
};

if (rooms.length > 0) {
  scenarios.rooms_scale = {
    executor: 'per-vu-iterations',
    exec: 'roomCycle',
    vus: rooms.length,
    iterations: Number(__ENV.GLPICHAT_ROOMS_ITERATIONS || 30),
    maxDuration: __ENV.GLPICHAT_ROOMS_MAX_DURATION || '10m',
  };
  thresholds['http_req_duration{scenario:rooms_scale}'] = [
    `p(95)<${Number(__ENV.GLPICHAT_ROOMS_P95_MS || 1000)}`,
  ];
}

if (Object.keys(scenarios).length === 0) {
  throw new Error('Enable the rooms_scale scenario by setting GLPICHAT_ROOMS_JSON to a JSON array of {ticket_id, widget_cookie, widget_csrf, guest_cookie}.');
}

export const options = {
  scenarios,
  thresholds,
};

export function roomCycle() {
  // Cada VU representa uma sala. k6 distribui __VU 1..N sequencialmente.
  const room = rooms[(__VU - 1) % rooms.length];
  roomsCounter.add(1);

  if (room.widget_cookie && room.widget_csrf) {
    const pollPayload = {
      _glpi_csrf_token: room.widget_csrf,
      'channels[0][tickets_id]': room.ticket_id,
      'channels[0][room_type]': 'public',
      'channels[0][after_id]': '0',
      'channels[0][after_event_id]': '0',
      'channels[0][is_typing]': '0',
    };

    const pollResponse = http.post(
      `${baseUrl}/plugins/glpichat/Widget/Poll`,
      pollPayload,
      {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-Glpi-Csrf-Token': room.widget_csrf,
          Cookie: room.widget_cookie,
        },
      }
    );

    check(pollResponse, {
      'Widget/Poll status is 200': (res) => res.status === 200,
    });
  }

  if (room.guest_cookie) {
    const syncResponse = http.get(
      `${baseUrl}/plugins/glpichat/Guest/Sync?last_message_id=0&last_event_id=0`,
      {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Cookie: room.guest_cookie,
        },
      }
    );

    check(syncResponse, {
      'Guest/Sync status is 200': (res) => res.status === 200,
    });

    sleep(2); // respeita throttle de Guest/Send (~2s) antes de enviar

    const sendResponse = http.post(
      `${baseUrl}/plugins/glpichat/Guest/Send`,
      {
        content: `rooms_scale load test message from room ${room.ticket_id} at ${Date.now()}`,
      },
      {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Cookie: room.guest_cookie,
        },
      }
    );

    check(sendResponse, {
      'Guest/Send status is 200 or throttled (429)': (res) => res.status === 200 || res.status === 429,
    });
  }

  sleep(1);
}
