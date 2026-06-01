<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use RuntimeException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_assert_can_read_room;
use function glpichat_current_user_id;
use function glpichat_events;
use function glpichat_list_user_channels;
use function glpichat_mark_read;
use function glpichat_messages;
use function glpichat_messages_before;
use function glpichat_participant_id_for_user;
use function glpichat_require_logged_csrf;
use function glpichat_room_by_id;
use function glpichat_room_id;

require_once dirname(__DIR__) . '/functions.php';

final class WidgetController extends AbstractController
{
    #[Route('/Widget/Channels', name: 'glpichat_widget_channels', methods: ['GET'])]
    public function channels(Request $request): Response
    {
        try {
            Session::checkLoginUser();
            $users_id = glpichat_current_user_id();
            $tickets_id = $request->query->getInt('tickets_id');
            $channels = glpichat_list_user_channels($users_id, $tickets_id);

            return new JsonResponse([
                'channels' => $channels,
                'current_user_id' => $users_id,
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Widget/Poll', name: 'glpichat_widget_poll', methods: ['POST'])]
    public function poll(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $users_id = glpichat_current_user_id();
            $channels = $request->request->all('channels');

            $result = [];
            $count = 0;
            foreach ($channels as $channel) {
                if ($count >= 20) {
                    break;
                }

                $tickets_id = (int) ($channel['tickets_id'] ?? 0);
                $room_type = (string) ($channel['room_type'] ?? '');
                $after_id = (int) ($channel['after_id'] ?? 0);
                $after_event_id = (int) ($channel['after_event_id'] ?? 0);
                if ($tickets_id <= 0 || !in_array($room_type, ['public', 'internal'], true)) {
                    continue;
                }

                glpichat_assert_can_read_room($tickets_id, $room_type);
                $rooms_id = glpichat_room_id($tickets_id, $room_type);
                $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
                $messages = glpichat_messages($rooms_id, $after_id);
                $events = glpichat_events($rooms_id, $after_event_id);

                $key = $tickets_id . ':' . $room_type;
                $result[$key] = [
                    'rooms_id' => $rooms_id,
                    'tickets_id' => $tickets_id,
                    'room_type' => $room_type,
                    'messages' => $messages,
                    'events' => $events,
                    'last_message_id' => count($messages) > 0 ? (int) end($messages)['id'] : $after_id,
                    'last_event_id' => count($events) > 0 ? (int) end($events)['id'] : $after_event_id,
                    'participants_id' => $participants_id,
                ];
                $count++;
            }

            return new JsonResponse([
                'channels' => $result,
                'server_time' => date('Y-m-d H:i:s'),
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Widget/History', name: 'glpichat_widget_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        try {
            Session::checkLoginUser();
            $rooms_id = $request->query->getInt('rooms_id');
            $before_id = $request->query->getInt('before_id');
            $limit = max(1, min(50, $request->query->getInt('limit') ?: 30));
            if ($rooms_id <= 0) {
                throw new RuntimeException('Invalid history parameters.');
            }

            $room = glpichat_room_by_id($rooms_id);
            glpichat_assert_can_read_room((int) $room['tickets_id'], (string) $room['room_type']);

            return new JsonResponse([
                'messages' => glpichat_messages_before($rooms_id, $before_id, $limit),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Widget/MarkRead', name: 'glpichat_widget_mark_read', methods: ['POST'])]
    public function markRead(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $users_id = glpichat_current_user_id();

            $rooms_id = $request->request->getInt('rooms_id');
            $last_read_message_id = $request->request->getInt('last_read_message_id');
            if ($rooms_id <= 0 || $last_read_message_id < 0) {
                throw new RuntimeException('Invalid mark read parameters.');
            }

            $room = glpichat_room_by_id($rooms_id);
            glpichat_assert_can_read_room((int) $room['tickets_id'], (string) $room['room_type']);
            $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
            glpichat_mark_read($rooms_id, $participants_id, $last_read_message_id);

            return new JsonResponse(['ok' => true, '_glpi_csrf_token' => Session::getNewCSRFToken()]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
