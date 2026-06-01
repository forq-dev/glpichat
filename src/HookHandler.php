<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonDBTM;
use Document;
use Document_Item;
use Ticket;

use function glpichat_add_message;
use function glpichat_record_event;
use function glpichat_room_id;

final class HookHandler
{
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
