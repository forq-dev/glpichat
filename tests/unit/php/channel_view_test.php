<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/unit.php';
require_once dirname(__DIR__, 3) . '/src/views/channel_view.php';

glpichat_unit_assert_true(
    glpichat_has_valid_room_participant_link(['id' => 7, 'rooms_id' => 12]),
    'Participant link is valid when room and participant ids are positive'
);
glpichat_unit_pass('Participant link is valid when room and participant ids are positive');

glpichat_unit_assert_true(
    !glpichat_has_valid_room_participant_link(['id' => 0, 'rooms_id' => 12]),
    'Participant link is invalid when participant id is missing'
);
glpichat_unit_pass('Participant link is invalid when participant id is missing');

$count_log = [];
$total_unread = glpichat_sum_unread_for_participants(
    [
        ['id' => 3, 'rooms_id' => 10],
        ['id' => 0, 'rooms_id' => 10],
        ['id' => 5, 'rooms_id' => 11],
    ],
    static function (int $rooms_id, int $participants_id) use (&$count_log): int {
        $count_log[] = [$rooms_id, $participants_id];
        return $rooms_id + $participants_id;
    }
);

glpichat_unit_assert_same(29, $total_unread, 'Unread total sums only valid participant links');
glpichat_unit_pass('Unread total sums only valid participant links');

glpichat_unit_assert_same_array(
    [
        [10, 3],
        [11, 5],
    ],
    $count_log,
    'Unread aggregation skips invalid links before calling counter'
);
glpichat_unit_pass('Unread aggregation skips invalid links before calling counter');

glpichat_unit_assert_same(
    '42:internal',
    glpichat_channel_key(42, 'internal'),
    'Channel key stays canonical'
);
glpichat_unit_pass('Channel key stays canonical');

glpichat_unit_assert_same_array(
    [
        'rooms_id' => 8,
        'tickets_id' => 13,
        'room_type' => 'public',
        'participants_id' => 21,
        'unread_count' => 5,
        'last_message_id' => 144,
        'ticket_status' => 2,
    ],
    glpichat_channel_output_row(8, 13, 'public', 21, 5, 144, 2),
    'Channel output row keeps canonical payload shape'
);
glpichat_unit_pass('Channel output row keeps canonical payload shape');

fwrite(STDOUT, "[PASS] Channel view unit tests completed\n");
