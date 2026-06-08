<?php

declare(strict_types=1);

// Stub glpichat_config_int before loading guest_db.php which calls it at runtime.
if (!function_exists('glpichat_config_int')) {
    function glpichat_config_int(string $key, int $default): int
    {
        return $default;
    }
}

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/db/guest_db.php';

/**
 * Minimal mock for the GLPI $DB global.
 * Simulates DB->request()->current() returning rows from a queue.
 */
final class ThrottleMockIterator implements Iterator
{
    private bool $rewound = false;

    public function __construct(private readonly ?array $row)
    {
    }

    public function current(): mixed
    {
        return $this->row;
    }

    public function key(): mixed
    {
        return 0;
    }

    public function next(): void
    {
        $this->rewound = false;
    }

    public function rewind(): void
    {
        $this->rewound = true;
    }

    public function valid(): bool
    {
        return $this->rewound;
    }
}

final class ThrottleMockDB
{
    /** @var list<array<string,mixed>|null> */
    private array $query_results;

    /**
     * @param list<array<string,mixed>|null> $query_results
     *   Rows returned in FIFO order for successive request() calls.
     *   Pass null as an element to simulate "row not found".
     */
    public function __construct(array $query_results)
    {
        $this->query_results = $query_results;
    }

    public function request(array $params): ThrottleMockIterator
    {
        $row = array_shift($this->query_results);
        return new ThrottleMockIterator($row ?? null);
    }

    public function tableExists(string $table): bool
    {
        return true;
    }
}

// ── glpichat_assert_guest_not_throttled ─────────────────────────────────────

// B-9D — last_send_at null: no throttle (fresh participant, never sent)
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => null]]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when last_send_at is null');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when last_send_at is null');
}

// B-9D — row not found: no throttle
$GLOBALS['DB'] = new ThrottleMockDB([null]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when participant row is not found');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when participant row is not found');
}

// B-9D — last_send_at 3 seconds ago: no throttle (above 2s interval)
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => date('Y-m-d H:i:s', time() - 3)]]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when last_send_at is 3 seconds ago');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when last_send_at is 3 seconds ago');
}

// B-9D — last_send_at 1 second ago: throttled (below 2s interval)
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => date('Y-m-d H:i:s', time() - 1)]]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_fail('Throttle must block when last_send_at is only 1 second ago');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws with code 429');
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Too many requests.'),
        'Throttle rejects recent send with canonical message'
    );
    glpichat_unit_pass('Throttle rejects request when last_send_at is 1 second ago with code 429');
}

// B-9D — last_send_at exactly now (0 seconds ago): throttled
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => date('Y-m-d H:i:s', time())]]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_fail('Throttle must block when last_send_at is right now');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws 429 when last_send_at is right now');
    glpichat_unit_pass('Throttle rejects request when last_send_at is right now with code 429');
}

// B-9D — custom min_interval_seconds: last_send_at 4 seconds ago with interval=5 → throttled
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => date('Y-m-d H:i:s', time() - 4)]]);
try {
    glpichat_assert_guest_not_throttled(1, 5);
    glpichat_unit_fail('Throttle must block when 4s elapsed with 5s interval');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws 429 with custom interval (4s < 5s)');
    glpichat_unit_pass('Throttle respects custom min_interval_seconds parameter');
}

// B-9D — custom min_interval_seconds: last_send_at 6 seconds ago with interval=5 → allowed
$GLOBALS['DB'] = new ThrottleMockDB([['last_send_at' => date('Y-m-d H:i:s', time() - 6)]]);
try {
    glpichat_assert_guest_not_throttled(1, 5);
    glpichat_unit_pass('Throttle allows request when 6s elapsed with 5s interval');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when 6s elapsed with 5s interval');
}

// ── glpichat_assert_accept_not_rate_limited ──────────────────────────────────

// B-9B — empty IP: skip rate-limit check (no DB call needed)
$GLOBALS['DB'] = new ThrottleMockDB([]);
try {
    glpichat_assert_accept_not_rate_limited('');
    glpichat_unit_pass('Rate limit skips check when IP is empty string');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Rate limit must not throw when IP is empty string');
}

// B-9B — IP with 0 failures: allowed
$GLOBALS['DB'] = new ThrottleMockDB([['COUNT' => 0]]);
try {
    glpichat_assert_accept_not_rate_limited('10.0.0.1');
    glpichat_unit_pass('Rate limit allows request when failure count is 0');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Rate limit must not block when there are no previous failures');
}

// B-9B — IP with 9 failures (< 10): allowed
$GLOBALS['DB'] = new ThrottleMockDB([['COUNT' => 9]]);
try {
    glpichat_assert_accept_not_rate_limited('192.168.1.1');
    glpichat_unit_pass('Rate limit allows request when failure count is 9 (below threshold)');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Rate limit must not block when failure count is 9');
}

// B-9B — IP with exactly 10 failures: blocked (>= 10 threshold)
$GLOBALS['DB'] = new ThrottleMockDB([['COUNT' => 10]]);
try {
    glpichat_assert_accept_not_rate_limited('192.168.1.1');
    glpichat_unit_fail('Rate limit must block when failure count reaches 10');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Rate limit throws with code 429 at threshold');
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Too many attempts. Try again later.'),
        'Rate limit throws canonical message at threshold'
    );
    glpichat_unit_pass('Rate limit blocks request when failure count is exactly 10 with code 429');
}

// B-9B — IP with 47 failures (>> 10): blocked
$GLOBALS['DB'] = new ThrottleMockDB([['COUNT' => 47]]);
try {
    glpichat_assert_accept_not_rate_limited('172.16.0.5');
    glpichat_unit_fail('Rate limit must block when failure count exceeds 10');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Rate limit throws with code 429 above threshold');
    glpichat_unit_pass('Rate limit blocks request when failure count exceeds 10 with code 429');
}

// B-9B — COUNT key missing from row (defensive): treated as 0, allowed
$GLOBALS['DB'] = new ThrottleMockDB([[]]);
try {
    glpichat_assert_accept_not_rate_limited('192.168.1.2');
    glpichat_unit_pass('Rate limit allows request when COUNT key is absent from DB row');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Rate limit must not block when COUNT key is absent');
}

fwrite(STDOUT, "[PASS] Guest throttle unit tests completed\n");
