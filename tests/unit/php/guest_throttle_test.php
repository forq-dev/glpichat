<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/db/guest_db.php';

/**
 * Minimal mock for the GLPI $DB global used by glpichat_assert_guest_not_throttled.
 * Simulates DB->request()->current() returning a single participant row.
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
    public function __construct(private readonly ?array $participant_row)
    {
    }

    public function request(array $params): ThrottleMockIterator
    {
        return new ThrottleMockIterator($this->participant_row);
    }
}

// Gap 4 — last_activity null: no throttle (fresh participant, never sent)
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => null]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when last_activity is null');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when last_activity is null');
}

// Gap 4 — row not found: no throttle
$GLOBALS['DB'] = new ThrottleMockDB(null);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when participant row is not found');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when participant row is not found');
}

// Gap 4 — last_activity 3 seconds ago: no throttle (above 2s interval)
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => date('Y-m-d H:i:s', time() - 3)]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_pass('Throttle allows request when last_activity is 3 seconds ago');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when last_activity is 3 seconds ago');
}

// Gap 4 — last_activity 1 second ago: throttled (below 2s interval)
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => date('Y-m-d H:i:s', time() - 1)]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_fail('Throttle must block when last_activity is only 1 second ago');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws with code 429');
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Too many requests.'),
        'Throttle rejects recent activity with canonical message'
    );
    glpichat_unit_pass('Throttle rejects request when last_activity is 1 second ago with code 429');
}

// Gap 4 — last_activity exactly now (0 seconds ago): throttled
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => date('Y-m-d H:i:s', time())]);
try {
    glpichat_assert_guest_not_throttled(1);
    glpichat_unit_fail('Throttle must block when last_activity is right now');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws 429 when last_activity is right now');
    glpichat_unit_pass('Throttle rejects request when last_activity is right now with code 429');
}

// Gap 4 — custom min_interval_seconds: last_activity 4 seconds ago with interval=5 → throttled
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => date('Y-m-d H:i:s', time() - 4)]);
try {
    glpichat_assert_guest_not_throttled(1, 5);
    glpichat_unit_fail('Throttle must block when 4s elapsed with 5s interval');
} catch (RuntimeException $error) {
    glpichat_unit_assert_same(429, $error->getCode(), 'Throttle throws 429 with custom interval (4s < 5s)');
    glpichat_unit_pass('Throttle respects custom min_interval_seconds parameter');
}

// Gap 4 — custom min_interval_seconds: last_activity 6 seconds ago with interval=5 → allowed
$GLOBALS['DB'] = new ThrottleMockDB(['last_activity' => date('Y-m-d H:i:s', time() - 6)]);
try {
    glpichat_assert_guest_not_throttled(1, 5);
    glpichat_unit_pass('Throttle allows request when 6s elapsed with 5s interval');
} catch (RuntimeException $error) {
    glpichat_unit_fail('Throttle must not block when 6s elapsed with 5s interval');
}

fwrite(STDOUT, "[PASS] Guest throttle unit tests completed\n");
