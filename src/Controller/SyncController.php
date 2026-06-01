<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_assert_can_read_room;
use function glpichat_events;
use function glpichat_messages;
use function glpichat_room_id;

require_once dirname(__DIR__) . '/functions.php';

final class SyncController extends AbstractController
{
    #[Route('/Sync', name: 'glpichat_sync', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $tickets_id = $request->query->getInt('tickets_id');
        $room_type = $request->query->getString('room_type');
        $last_message_id = $request->query->getInt('last_message_id');
        $last_event_id = $request->query->getInt('last_event_id');

        if ($tickets_id <= 0 || !in_array($room_type, ['public', 'internal'], true)) {
            throw new RuntimeException('Invalid sync parameters.');
        }

        glpichat_assert_can_read_room($tickets_id, $room_type);
        $rooms_id = glpichat_room_id($tickets_id, $room_type);

        return new JsonResponse([
            'rooms_id' => $rooms_id,
            'messages' => glpichat_messages($rooms_id, $last_message_id),
            'events' => glpichat_events($rooms_id, $last_event_id),
        ]);
    }
}
