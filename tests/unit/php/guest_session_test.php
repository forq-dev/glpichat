<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/functions.php';
require_once dirname(__DIR__, 3) . '/src/flows/guest_flow.php';

// Mock DB Class to simulate $DB operations
class GuestSessionMockDB
{
    public $queries = [];
    public $results = [];
    public $update_called = false;
    public $insert_called = false;

    public function request(array $params)
    {
        $this->queries[] = ['request', $params];
        if (($params['FROM'] ?? '') === 'glpi_configs') {
            return new ArrayIterator([
                ['name' => 'guest_session_ttl', 'value' => '3600']
            ]);
        }
        if (($params['FROM'] ?? '') === 'glpi_plugin_glpichat_rooms') {
            return new ArrayIterator([
                ['id' => 20, 'tickets_id' => 30]
            ]);
        }
        return new ArrayIterator($this->results);
    }

    public function update(string $table, array $data, array $where)
    {
        $this->queries[] = ['update', $table, $data, $where];
        $this->update_called = true;
        return true;
    }

    public function insert(string $table, array $data)
    {
        $this->queries[] = ['insert', $table, $data];
        $this->insert_called = true;
        return 1;
    }

    public function insertId()
    {
        return 1;
    }
}

// 1. Test glpichat_guest_participant with invalid/missing session
$mockDB = new GuestSessionMockDB();
$mockDB->results = []; // No participant found
$GLOBALS['DB'] = $mockDB;

try {
    glpichat_guest_participant('invalid-token');
    glpichat_unit_fail('Should throw exception for invalid session token');
} catch (RuntimeException $e) {
    glpichat_unit_assert_true(
        str_contains($e->getMessage(), 'Essa sessão de convidado não é mais válida.'),
        'Throws invalid guest session exception'
    );
    glpichat_unit_pass('Throws invalid guest session exception');
}

// 2. Test glpichat_guest_participant with active session inside TTL
$mockDB = new GuestSessionMockDB();
$mockDB->results = [[
    'id' => 10,
    'rooms_id' => 20,
    'tickets_id' => 30,
    'guest_name' => 'Alice',
    'joined_at' => date('Y-m-d H:i:s'),
    'last_activity' => date('Y-m-d H:i:s'),
    'participant_type' => GLPICHAT_ACTOR_GUEST,
]];
$GLOBALS['DB'] = $mockDB;

$participant = glpichat_guest_participant('active-token');
glpichat_unit_assert_same(10, (int)$participant['id'], 'Finds participant id');
glpichat_unit_assert_same('Alice', $participant['guest_name'], 'Finds guest name');
glpichat_unit_assert_true(!$mockDB->update_called, 'Does not update last_activity for active participant on fetch');
glpichat_unit_pass('Finds active participant without updating activity');

// 3. Test glpichat_guest_participant with expired session
$mockDB = new GuestSessionMockDB();
// Simulate last activity 2 hours ago (TTL is 1 hour default)
$lastActivity = date('Y-m-d H:i:s', time() - 7200);
$mockDB->results = [[
    'id' => 10,
    'rooms_id' => 20,
    'tickets_id' => 30,
    'guest_name' => 'Alice',
    'joined_at' => $lastActivity,
    'last_activity' => $lastActivity,
    'participant_type' => GLPICHAT_ACTOR_GUEST,
]];
$GLOBALS['DB'] = $mockDB;

try {
    glpichat_guest_participant('expired-token');
    glpichat_unit_fail('Should throw exception for expired session');
} catch (RuntimeException $e) {
    glpichat_unit_assert_true(
        str_contains($e->getMessage(), 'Essa sessão de convidado não é mais válida.'),
        'Throws unified invalid session exception on expired session (G4)'
    );
    glpichat_unit_assert_true($mockDB->update_called, 'Updates participant table to set left_at and clear session token');
    glpichat_unit_pass('Throws unified invalid session exception on expired session and terminates participant (G4)');
}

// 4. Test glpichat_disconnect_participant
$mockDB = new GuestSessionMockDB();
$mockDB->results = [[
    'id' => 10,
    'rooms_id' => 20,
    'tickets_id' => 30,
    'guest_name' => 'Alice',
    'joined_at' => date('Y-m-d H:i:s'),
    'last_activity' => date('Y-m-d H:i:s'),
    'participant_type' => GLPICHAT_ACTOR_GUEST,
]];
$GLOBALS['DB'] = $mockDB;
$now = date('Y-m-d H:i:s');

glpichat_disconnect_participant(10, $now);
glpichat_unit_assert_true($mockDB->update_called, 'Disconnect updates participant table');

// Verify correct update parameters
$updateQuery = null;
foreach ($mockDB->queries as $q) {
    if ($q[0] === 'update' && $q[1] === 'glpi_plugin_glpichat_participants') {
        $updateQuery = $q;
        break;
    }
}
glpichat_unit_assert_true($updateQuery !== null, 'Disconnect updates glpi_plugin_glpichat_participants');
glpichat_unit_assert_same($now, $updateQuery[2]['left_at'], 'Sets left_at correctly');
glpichat_unit_assert_true(array_key_exists('session_token_hash', $updateQuery[2]) && $updateQuery[2]['session_token_hash'] === null, 'Clears session token hash');
glpichat_unit_pass('Disconnect participant terminates session hash and sets left_at');

fwrite(STDOUT, "[PASS] Guest session unit tests completed\n");
