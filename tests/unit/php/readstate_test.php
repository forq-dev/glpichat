<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/domain/readstate.php';

glpichat_unit_assert_same_array(
    [
        'rooms_id' => 12,
        ['id' => ['>', 33]],
        [
            'NOT' => [
                'participant_type' => 'user',
                'users_id' => 7,
            ],
        ],
    ],
    glpichat_unread_where_for_participant([
        'id' => 9,
        'rooms_id' => 12,
        'participant_type' => 'user',
        'users_id' => 7,
    ], 33),
    'Unread where excludes own user messages'
);
glpichat_unit_pass('Unread where excludes own user messages');

glpichat_unit_assert_same_array(
    [
        'rooms_id' => 22,
        ['id' => ['>', 0]],
        [
            'NOT' => [
                'participants_id' => 41,
            ],
        ],
    ],
    glpichat_unread_where_for_participant([
        'id' => 41,
        'rooms_id' => 22,
        'participant_type' => 'guest',
        'users_id' => 0,
    ], 0),
    'Unread where excludes own guest messages'
);
glpichat_unit_pass('Unread where excludes own guest messages');

glpichat_unit_assert_same(
    48,
    glpichat_next_readstate_message_id(48, 12),
    'Next readstate message id never goes backwards'
);
glpichat_unit_pass('Next readstate message id never goes backwards');

glpichat_unit_assert_same_array(
    [
        'last_read_messages_id' => 19,
        'date_mod' => '2026-06-03 19:00:00',
    ],
    glpichat_readstate_update_payload(5, 19, '2026-06-03 19:00:00'),
    'Readstate update payload keeps canonical fields'
);
glpichat_unit_pass('Readstate update payload keeps canonical fields');

glpichat_unit_assert_same_array(
    [
        'rooms_id' => 4,
        'participants_id' => 8,
        'last_read_messages_id' => 0,
        'date_mod' => '2026-06-03 19:01:00',
    ],
    glpichat_readstate_insert_payload(4, 8, -3, '2026-06-03 19:01:00'),
    'Readstate insert payload clamps negative requested ids'
);
glpichat_unit_pass('Readstate insert payload clamps negative requested ids');

try {
    glpichat_assert_valid_readstate_participant(null);
    glpichat_unit_fail('Readstate participant guard must reject missing participant');
} catch (RuntimeException $error) {
    glpichat_unit_assert_true(
        str_contains($error->getMessage(), 'Participante do chat não encontrado.'),
        'Readstate participant guard keeps canonical error'
    );
    glpichat_unit_pass('Readstate participant guard keeps canonical error');
}

fwrite(STDOUT, "[PASS] Readstate unit tests completed\n");
