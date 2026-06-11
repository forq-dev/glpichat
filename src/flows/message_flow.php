<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\File\UploadedFile;

require_once dirname(__DIR__) . '/domain/domain.php';

function glpichat_validate_send_message_input(int $tickets_id, string $room_type, string $content): string
{
    $trimmed_content = trim($content);
    if ($tickets_id <= 0 || $trimmed_content === '' || !glpichat_is_valid_room_type($room_type)) {
        throw new RuntimeException('Parâmetros de mensagem inválidos.');
    }

    return $trimmed_content;
}

function glpichat_attachment_message_content(string $original_name, string $source_label): string
{
    return 'Attachment added from ' . $source_label . ': ' . $original_name;
}

function glpichat_validate_upload_input(int $tickets_id, string $room_type, mixed $file): UploadedFile
{
    if ($tickets_id <= 0 || !glpichat_is_valid_room_type($room_type) || !$file instanceof UploadedFile) {
        throw new RuntimeException('Parâmetros de upload inválidos.');
    }

    return $file;
}

function glpichat_send_message_use_case(
    int $tickets_id,
    string $room_type,
    string $content,
    callable $assert_can_send,
    callable $current_user_id,
    callable $room_id,
    callable $participant_id_for_user,
    callable $add_followup,
    callable $add_message
): int {
    $validated_content = glpichat_validate_send_message_input($tickets_id, $room_type, $content);
    $assert_can_send($tickets_id, $room_type);
    $users_id = (int) $current_user_id();
    $rooms_id = (int) $room_id($tickets_id, $room_type);
    $participants_id = (int) $participant_id_for_user($rooms_id, $users_id);
    $followups_id = (int) $add_followup($tickets_id, $users_id, $validated_content, glpichat_is_private_room_type($room_type));

    return (int) $add_message(
        $tickets_id,
        $room_type,
        GLPICHAT_ACTOR_USER,
        $users_id,
        $participants_id,
        $validated_content,
        0,
        $followups_id
    );
}

/**
 * @return array{message_id:int,documents_id:int}
 */
function glpichat_upload_message_use_case(
    int $tickets_id,
    string $room_type,
    mixed $file,
    string $source_label,
    callable $assert_can_send,
    callable $current_user_id,
    callable $upload_document,
    callable $add_followup,
    callable $room_id,
    callable $participant_id_for_user,
    callable $add_message
): array {
    $validated_file = glpichat_validate_upload_input($tickets_id, $room_type, $file);
    $assert_can_send($tickets_id, $room_type);
    $users_id = (int) $current_user_id();
    $documents_id = (int) $upload_document($tickets_id, $validated_file, true);
    $content = glpichat_attachment_message_content($validated_file->getClientOriginalName(), $source_label);
    $followups_id = (int) $add_followup($tickets_id, $users_id, $content, glpichat_is_private_room_type($room_type));
    $rooms_id = (int) $room_id($tickets_id, $room_type);
    $participants_id = (int) $participant_id_for_user($rooms_id, $users_id);
    $message_id = (int) $add_message(
        $tickets_id,
        $room_type,
        GLPICHAT_ACTOR_USER,
        $users_id,
        $participants_id,
        $content,
        $documents_id,
        $followups_id
    );

    return [
        'message_id' => $message_id,
        'documents_id' => $documents_id,
    ];
}
