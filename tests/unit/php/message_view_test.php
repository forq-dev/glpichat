<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/views/message_view.php';

glpichat_unit_assert_same(
    'attachment',
    glpichat_message_type_from_document_id(12),
    'Message type becomes attachment when document id is present'
);
glpichat_unit_pass('Message type becomes attachment when document id is present');

glpichat_unit_assert_same(
    'Nova mensagem no chat',
    glpichat_message_preview('   ', 'Nova mensagem no chat', 180),
    'Preview falls back when content is blank'
);
glpichat_unit_pass('Preview falls back when content is blank');

glpichat_unit_assert_same(
    str_repeat('a', 177) . '...',
    glpichat_message_preview(str_repeat('a', 190), 'fallback', 180),
    'Preview truncates long content with canonical ellipsis'
);
glpichat_unit_pass('Preview truncates long content with canonical ellipsis');

glpichat_unit_assert_same(
    'Convidado #45',
    glpichat_sender_name_for_guest(45, null, 'Convidado #', ''),
    'Guest sender falls back to canonical label when guest name is missing'
);
glpichat_unit_pass('Guest sender falls back to canonical label when guest name is missing');

glpichat_unit_assert_same(
    'Maria (Convidado)',
    glpichat_sender_name_for_actor(
        GLPICHAT_ACTOR_GUEST,
        0,
        9,
        '',
        'Maria',
        'User #',
        'Convidado #',
        ' (Convidado)',
        'Sistema'
    ),
    'Actor sender name appends guest suffix in poll payload formatting'
);
glpichat_unit_pass('Actor sender name appends guest suffix in poll payload formatting');

glpichat_unit_assert_same_array(
    [
        'id' => 18,
        'actor_type' => 'user',
        'users_id' => 11,
        'participants_id' => 0,
        'sender_name' => 'Tech User',
        'message_type' => 'message',
        'content' => 'hello',
        'documents_id' => 0,
        'itilfollowups_id' => 22,
        'date_creation' => '2026-06-03 10:00:00',
    ],
    glpichat_message_output_row([
        'id' => 18,
        'participant_type' => 'user',
        'users_id' => 11,
        'participants_id' => 0,
        'message_type' => 'message',
        'content' => 'hello',
        'documents_id' => 0,
        'itilfollowups_id' => 22,
        'date_creation' => '2026-06-03 10:00:00',
    ], 'Tech User'),
    'Message output row stays canonical'
);
glpichat_unit_pass('Message output row stays canonical');

glpichat_unit_assert_same_array(
    [
        'id' => 7,
        'event_type' => 'message_created',
        'actor_type' => 'guest',
        'users_id' => 0,
        'participants_id' => 44,
        'payload' => ['message_id' => 99],
        'date_creation' => '2026-06-03 10:02:00',
    ],
    glpichat_event_output_row([
        'id' => 7,
        'event_type' => 'message_created',
        'actor_type' => 'guest',
        'users_id' => 0,
        'participants_id' => 44,
        'payload_json' => '{"message_id":99}',
        'date_creation' => '2026-06-03 10:02:00',
    ]),
    'Event output row decodes payload json and preserves event structure'
);
glpichat_unit_pass('Event output row decodes payload json and preserves event structure');

fwrite(STDOUT, "[PASS] Message view unit tests completed\n");
