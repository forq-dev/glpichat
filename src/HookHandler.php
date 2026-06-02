<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonDBTM;
use Document;
use Document_Item;
use NotificationTargetTicket;
use Ticket;

use function glpichat_add_message;
use function glpichat_record_event;
use function glpichat_room_id;

final class HookHandler
{
    public static function itemGetEvents(mixed $target): void
    {
        if (!$target instanceof NotificationTargetTicket) {
            return;
        }

        $target->events['glpichat_public_message'] = __('GLPI CHAT: nova mensagem do solicitante');
        $target->events['glpichat_internal_message'] = __('GLPI CHAT: nova mensagem interna');
        $target->events['glpichat_guest_message'] = __('GLPI CHAT: nova mensagem de convidado');
    }

    public static function itemGetDatas(mixed $target): void
    {
        if (!$target instanceof NotificationTargetTicket) {
            return;
        }

        $event = (string) ($target->raiseevent ?? '');
        if (!in_array($event, ['glpichat_public_message', 'glpichat_internal_message', 'glpichat_guest_message'], true)) {
            return;
        }

        $ticket = $target->obj;
        if (!$ticket instanceof Ticket) {
            return;
        }

        $recipient = $target->recipient_data;
        $users_id = (int) ($recipient['items_id'] ?? 0);
        $message = glpichat_latest_message_for_ticket((int) $ticket->getID());
        $raw_unread = $users_id > 0 ? glpichat_unread_count_for_user_ticket($users_id, (int) $ticket->getID()) : 0;
        $display_unread = glpichat_display_unread_count($raw_unread);

        $target->data['##glpichat.ticket_label##'] = sprintf('%s #%d', (string) ($ticket->fields['name'] ?? 'Chamado'), (int) $ticket->getID());
        $target->data['##glpichat.sender_name##'] = (string) ($message['sender_name'] ?? 'Sistema');
        $target->data['##glpichat.message_preview##'] = (string) ($message['preview'] ?? 'Nova mensagem no chat');
        $target->data['##glpichat.unread_count##'] = (string) $display_unread;
        $target->data['##glpichat.unread_badge##'] = $display_unread > 0 ? sprintf('(%d)', $display_unread) : '';
    }

    public static function itemAdd(CommonDBTM $item): void
    {
        if (($GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] ?? false) === true) {
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
}
