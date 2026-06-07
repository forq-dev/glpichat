<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonDBTM;
use CommonITILActor;
use Document;
use Document_Item;
use Dropdown;
use ITILFollowup;
use NotificationTargetTicket;
use Session;
use Ticket;

use function glpichat_add_message;
use function glpichat_can_user_read_notification_room;
use function glpichat_display_unread_count;
use function glpichat_is_ticket_terminal_status;
use function glpichat_latest_message_for_room;
use function glpichat_notification_message_data;
use function glpichat_record_event;
use function glpichat_room_id;
use function glpichat_unread_count_for_user_room;

require_once __DIR__ . '/flows/notification_flow.php';

final class HookHandler
{
    /** @var array<int, array{assigned_users: array<int, string>, assigned_groups: array<int, string>}> */
    private static array $ticketSnapshots = [];

    public static function itemGetEvents(mixed $target): void
    {
        if (!$target instanceof NotificationTargetTicket) {
            return;
        }

        $target->events['glpichat_public_message'] = __('GLPI CHAT: nova mensagem do solicitante');
        $target->events['glpichat_internal_message'] = __('GLPI CHAT: nova mensagem interna');
        $target->events['glpichat_guest_message'] = __('GLPI CHAT: nova mensagem de convidado');
        $target->events['glpichat_guest_joined'] = __('GLPI CHAT: convidado entrou no chat');
        $target->events['glpichat_guest_left'] = __('GLPI CHAT: convidado saiu do chat');
        $target->events['glpichat_guest_left_timeout'] = __('GLPI CHAT: sessao do convidado expirou');
    }

    public static function itemGetDatas(mixed $target): void
    {
        $event_action_map = [
            'glpichat_public_message' => 'Nova mensagem do solicitante',
            'glpichat_internal_message' => 'Nova mensagem interna',
            'glpichat_guest_message' => 'Nova mensagem de convidado',
            'glpichat_guest_joined' => 'Convidado entrou no chat',
            'glpichat_guest_left' => 'Convidado saiu do chat',
            'glpichat_guest_left_timeout' => 'Sessao do convidado expirou',
        ];

        $channel_type_map = [
            GLPICHAT_ROOM_PUBLIC => 'Solicitante',
            GLPICHAT_ROOM_INTERNAL => 'Discussao tecnica',
        ];

        if (!$target instanceof NotificationTargetTicket) {
            return;
        }

        $event = (string) ($target->raiseevent ?? '');
        if (!in_array($event, ['glpichat_public_message', 'glpichat_internal_message', 'glpichat_guest_message', 'glpichat_guest_joined', 'glpichat_guest_left', 'glpichat_guest_left_timeout'], true)) {
            return;
        }

        $ticket = $target->obj;
        if (!$ticket instanceof Ticket) {
            return;
        }

        if ($event === 'glpichat_guest_joined') {
            $guest_name = (string) ($target->options['glpichat_guest_name'] ?? 'Convidado');
            $target->data['##glpichat.event_action##'] = $event_action_map[$event];
            $target->data['##glpichat.sender_name##'] = $guest_name;
            $target->data['##glpichat.guest_name##'] = $guest_name;
            $target->data['##glpichat.message_preview##'] = '';
            $target->data['##glpichat.channel_type##'] = '';
            $target->data['##glpichat.unread_count##'] = '0';
            $target->data['##glpichat.unread_badge##'] = '';
            return;
        }

        if ($event === 'glpichat_guest_left') {
            $guest_name = (string) ($target->options['glpichat_guest_name'] ?? 'Convidado');
            $target->data['##glpichat.event_action##'] = $event_action_map[$event];
            $target->data['##glpichat.sender_name##'] = $guest_name;
            $target->data['##glpichat.guest_name##'] = $guest_name;
            $target->data['##glpichat.message_preview##'] = '';
            $target->data['##glpichat.channel_type##'] = '';
            $target->data['##glpichat.unread_count##'] = '0';
            $target->data['##glpichat.unread_badge##'] = '';
            return;
        }

        if ($event === 'glpichat_guest_left_timeout') {
            $guest_name = (string) ($target->options['glpichat_guest_name'] ?? 'Convidado');
            $target->data['##glpichat.event_action##'] = $event_action_map[$event];
            $target->data['##glpichat.sender_name##'] = $guest_name;
            $target->data['##glpichat.guest_name##'] = $guest_name;
            $target->data['##glpichat.message_preview##'] = '';
            $target->data['##glpichat.channel_type##'] = '';
            $target->data['##glpichat.unread_count##'] = '0';
            $target->data['##glpichat.unread_badge##'] = '';
            return;
        }

        $recipient = $target->recipient_data;
        $users_id = (int) ($recipient['items_id'] ?? 0);
        $message_data = glpichat_notification_message_data(
            $event,
            (int) $ticket->getID(),
            $users_id,
            'glpichat_room_id',
            'glpichat_can_user_read_notification_room',
            'glpichat_latest_message_for_room',
            'glpichat_unread_count_for_user_room',
            'glpichat_display_unread_count'
        );

        $room_type = (string) ($target->options['glpichat_room_type'] ?? '');
        $is_authorized = (bool) ($message_data['authorized'] ?? false);
        $target->data['##glpichat.event_action##'] = $is_authorized ? ($event_action_map[$event] ?? '') : '';
        $target->data['##glpichat.sender_name##'] = $message_data['sender_name'];
        $target->data['##glpichat.guest_name##'] = '';
        $target->data['##glpichat.message_preview##'] = $message_data['preview'];
        $target->data['##glpichat.channel_type##'] = $is_authorized ? ($channel_type_map[$room_type] ?? '') : '';
        $target->data['##glpichat.unread_count##'] = $message_data['unread_count'];
        $target->data['##glpichat.unread_badge##'] = $message_data['unread_badge'];
    }

    public static function preItemUpdate(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket || !$item->getID()) {
            return;
        }

        self::$ticketSnapshots[(int) $item->getID()] = [
            'assigned_users' => self::assignedUsers((int) $item->getID()),
            'assigned_groups' => self::assignedGroups((int) $item->getID()),
        ];
    }

    public static function itemUpdate(CommonDBTM $item): void
    {
        if (!$item instanceof Ticket || !$item->getID()) {
            return;
        }

        $tickets_id = (int) $item->getID();
        $status = (int) ($item->fields['status'] ?? 0);
        $events = self::ticketFieldEvents($item, $status);

        if (array_key_exists($tickets_id, self::$ticketSnapshots)) {
            $snapshot = self::$ticketSnapshots[$tickets_id];
            unset(self::$ticketSnapshots[$tickets_id]);

            $current_users = self::assignedUsers($tickets_id);
            if ($current_users !== $snapshot['assigned_users']) {
                $events[] = [
                    'event_type' => 'ticket_assigned_users_changed',
                    'payload' => [
                        'old_assigned_users' => array_values($snapshot['assigned_users']),
                        'new_assigned_users' => array_values($current_users),
                    ],
                ];
            }

            $current_groups = self::assignedGroups($tickets_id);
            if ($current_groups !== $snapshot['assigned_groups']) {
                $events[] = [
                    'event_type' => 'ticket_assigned_groups_changed',
                    'payload' => [
                        'old_assigned_groups' => array_values($snapshot['assigned_groups']),
                        'new_assigned_groups' => array_values($current_groups),
                    ],
                ];
            }
        }

        foreach ($events as $event) {
            self::recordTicketEvent($tickets_id, (string) $event['event_type'], (array) $event['payload']);
        }
    }

    public static function itemAdd(CommonDBTM $item): void
    {
        if (($GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] ?? false) === true) {
            return;
        }

        if ($item instanceof ITILFollowup) {
            self::recordExternalFollowupEvent($item);
            return;
        }

        if (!$item instanceof Document_Item) {
            return;
        }

        if (($item->fields['itemtype'] ?? '') !== Ticket::class) {
            return;
        }

        $tickets_id = (int) ($item->fields['items_id'] ?? 0);
        $documents_id = (int) ($item->fields['documents_id'] ?? 0);
        if ($tickets_id <= 0 || $documents_id <= 0) {
            return;
        }

        $document = new Document();
        if (!$document->getFromDB($documents_id)) {
            return;
        }

        $rooms_id = glpichat_room_id($tickets_id, 'public');
        $content = 'Attachment added to ticket: ' . (string) $document->fields['name'];
        $message_id = glpichat_add_message($tickets_id, 'public', 'user', (int) ($document->fields['users_id'] ?? 0), 0, $content, $documents_id, 0);
        glpichat_record_event($rooms_id, $tickets_id, 'attachment_uploaded', 'user', (int) ($document->fields['users_id'] ?? 0), 0, [
            'message_id' => $message_id,
            'documents_id' => $documents_id,
            'source' => 'ticket',
        ]);
    }

    /** @return array<int, string> */
    private static function assignedUsers(int $tickets_id): array
    {
        global $DB;

        $users = [];
        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $tickets_id,
                'type' => CommonITILActor::ASSIGN,
            ],
            'ORDER' => ['users_id ASC'],
        ]);

        foreach ($iterator as $row) {
            $users_id = (int) ($row['users_id'] ?? 0);
            if ($users_id <= 0) {
                continue;
            }

            $users[$users_id] = getUserName($users_id) ?: ('Usuario #' . $users_id);
        }

        return $users;
    }

    /** @return array<int, string> */
    private static function assignedGroups(int $tickets_id): array
    {
        global $DB;

        $groups = [];
        $iterator = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM' => 'glpi_groups_tickets',
            'WHERE' => [
                'tickets_id' => $tickets_id,
                'type' => CommonITILActor::ASSIGN,
            ],
            'ORDER' => ['groups_id ASC'],
        ]);

        foreach ($iterator as $row) {
            $groups_id = (int) ($row['groups_id'] ?? 0);
            if ($groups_id <= 0) {
                continue;
            }

            $groups[$groups_id] = (string) Dropdown::getDropdownName('glpi_groups', $groups_id);
        }

        return $groups;
    }

    private static function recordTicketEvent(int $tickets_id, string $event_type, array $payload): void
    {
        foreach (['public', 'internal'] as $room_type) {
            $rooms_id = glpichat_room_id($tickets_id, $room_type);
            glpichat_record_event($rooms_id, $tickets_id, $event_type, 'system', 0, 0, $payload);
        }
    }

    /** @return array<int, array{event_type: string, payload: array<string, mixed>}> */
    private static function ticketFieldEvents(Ticket $item, int $status): array
    {
        $events = [];
        $field_handlers = [
            'status' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_status_changed',
                'payload' => [
                    'old_status' => $old_value,
                    'new_status' => $new_value,
                    'old_label' => Ticket::getStatus($old_value),
                    'new_label' => Ticket::getStatus($new_value),
                    'is_terminal' => glpichat_is_ticket_terminal_status($new_value),
                ],
            ],
            'priority' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_priority_changed',
                'payload' => [
                    'old_priority' => $old_value,
                    'new_priority' => $new_value,
                    'old_label' => Ticket::getPriorityName($old_value),
                    'new_label' => Ticket::getPriorityName($new_value),
                ],
            ],
            'urgency' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_urgency_changed',
                'payload' => [
                    'old_urgency' => $old_value,
                    'new_urgency' => $new_value,
                    'old_label' => Ticket::getUrgencyName($old_value),
                    'new_label' => Ticket::getUrgencyName($new_value),
                ],
            ],
            'impact' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_impact_changed',
                'payload' => [
                    'old_impact' => $old_value,
                    'new_impact' => $new_value,
                    'old_label' => Ticket::getImpactName($old_value),
                    'new_label' => Ticket::getImpactName($new_value),
                ],
            ],
            'type' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_type_changed',
                'payload' => [
                    'old_type' => $old_value,
                    'new_type' => $new_value,
                    'old_label' => (string) Ticket::getTicketTypeName($old_value),
                    'new_label' => (string) Ticket::getTicketTypeName($new_value),
                ],
            ],
            'itilcategories_id' => static fn (int $old_value, int $new_value): array => [
                'event_type' => 'ticket_category_changed',
                'payload' => [
                    'old_itilcategories_id' => $old_value,
                    'new_itilcategories_id' => $new_value,
                    'old_label' => self::ticketCategoryLabel($old_value),
                    'new_label' => self::ticketCategoryLabel($new_value),
                ],
            ],
        ];

        foreach ($field_handlers as $field_name => $handler) {
            $has_old_value = array_key_exists($field_name, $item->oldvalues);
            $new_value = $field_name === 'status'
                ? $status
                : (int) ($item->fields[$field_name] ?? 0);
            $old_value = $has_old_value
                ? (int) $item->oldvalues[$field_name]
                : $new_value;

            if (!$has_old_value && !in_array($field_name, $item->updates, true)) {
                continue;
            }

            if ($old_value === $new_value) {
                continue;
            }

            $events[] = $handler($old_value, $new_value);
        }

        return $events;
    }

    private static function recordExternalFollowupEvent(ITILFollowup $followup): void
    {
        $tickets_id = (int) ($followup->fields['items_id'] ?? 0);
        if ($tickets_id <= 0 || ($followup->fields['itemtype'] ?? '') !== Ticket::class) {
            return;
        }

        $requesttypes_id = (int) ($followup->fields['requesttypes_id'] ?? 0);
        if (self::isGlpiChatRequestType($requesttypes_id)) {
            return;
        }

        $users_id = (int) ($followup->fields['users_id'] ?? 0);
        $payload = [
            'itilfollowups_id' => (int) $followup->getID(),
            'source_label' => self::followupSourceLabel($requesttypes_id),
            'author_name' => self::followupAuthorName($users_id),
            'is_private' => (int) ($followup->fields['is_private'] ?? 0) === 1,
        ];

        self::recordTicketEvent($tickets_id, 'ticket_followup_added', $payload);
    }

    private static function isGlpiChatRequestType(int $requesttypes_id): bool
    {
        if ($requesttypes_id <= 0) {
            return false;
        }

        return strcasecmp(self::followupSourceLabel($requesttypes_id), 'GLPI Chat') === 0;
    }

    private static function followupSourceLabel(int $requesttypes_id): string
    {
        if ($requesttypes_id <= 0) {
            return 'GLPI';
        }

        $label = (string) Dropdown::getDropdownName('glpi_requesttypes', $requesttypes_id);

        return $label !== '' ? $label : 'GLPI';
    }

    private static function followupAuthorName(int $users_id): string
    {
        if ($users_id <= 0) {
            return 'Sistema';
        }

        $name = getUserName($users_id);

        return is_string($name) && $name !== '' ? $name : ('Usuario #' . $users_id);
    }

    /**
     * Hook: Hooks::ITEM_CAN — intercepts Document::can() to enforce
     * plugin_glpichat_internal READ for documents uploaded to the internal channel.
     *
     * Called by CommonDBTM::can() via Plugin::doHook(Hooks::ITEM_CAN, $document).
     * If $item->right is changed to any value != the original right, can() returns false.
     *
     * Only blocks access when ALL three conditions are true:
     *  1. The item is a Document instance with a valid ID.
     *  2. The document is referenced in glpi_plugin_glpichat_messages with a room whose
     *     room_type = 'internal'.
     *  3. The current user does NOT have plugin_glpichat_internal READ.
     *
     * Documents not linked to the internal channel are left untouched.
     *
     * @param Document $item The Document being checked (passed by reference via doHook).
     */
    public static function canDocument(Document $item): void
    {
        global $DB;

        $documents_id = (int) ($item->fields['id'] ?? 0);
        if ($documents_id <= 0) {
            return;
        }

        // Skip if the right being checked is not READ — do not interfere with
        // UPDATE / DELETE / PURGE checks triggered by other workflows.
        if ((int) $item->right !== READ) {
            return;
        }

        // Check whether this document belongs to an internal-channel message.
        // A single row is enough; LIMIT 1 makes the query cheap.
        $iterator = $DB->request([
            'SELECT' => ['glpi_plugin_glpichat_rooms.room_type'],
            'FROM'   => 'glpi_plugin_glpichat_messages',
            'LEFT JOIN' => [
                'glpi_plugin_glpichat_rooms' => [
                    'ON' => [
                        'glpi_plugin_glpichat_messages' => 'rooms_id',
                        'glpi_plugin_glpichat_rooms'    => 'id',
                    ],
                ],
            ],
            'WHERE'  => [
                'glpi_plugin_glpichat_messages.documents_id' => $documents_id,
                'glpi_plugin_glpichat_rooms.room_type'       => GLPICHAT_ROOM_INTERNAL,
            ],
            'LIMIT'  => 1,
        ]);

        if (count($iterator) === 0) {
            // Document is not linked to the internal channel — do not restrict.
            return;
        }

        // Document belongs to the internal channel: require plugin_glpichat_internal READ.
        if (!Session::haveRight('plugin_glpichat_internal', READ)) {
            // Setting right to null (≠ original value) causes CommonDBTM::can() to return false.
            $item->right = null;
        }
    }

    private static function ticketCategoryLabel(int $itilcategories_id): string
    {
        if ($itilcategories_id <= 0) {
            return 'Sem categoria';
        }

        $label = (string) Dropdown::getDropdownName('glpi_itilcategories', $itilcategories_id);

        return $label !== '' ? $label : ('Categoria #' . $itilcategories_id);
    }
}
