<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use RuntimeException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_assert_can_read_room;
use function glpichat_current_user_id;
use function glpichat_events;
use function glpichat_get_typing_participants;
use function glpichat_list_user_channels;
use function glpichat_mark_read;
use function glpichat_room_last_event_id;
use function glpichat_room_last_message_id;
use function glpichat_messages;
use function glpichat_messages_before;
use function glpichat_participant_id_for_user;
use function glpichat_require_logged_csrf;
use function glpichat_room_id;
use function glpichat_ticket_status;
use function glpichat_unread_count;
use function glpichat_update_participant_activity;
use function glpichat_expire_inactive_user_participants;
use function glpichat_disconnect_user_participant;

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/flows/widget_access_flow.php';

final class WidgetController extends AbstractController
{
    #[Route('/Widget/Channels', name: 'glpichat_widget_channels', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
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
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function poll(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $users_id = glpichat_current_user_id();

            // Expiração oportunista de inatividade curta (2 min)
            glpichat_expire_inactive_user_participants();

            $channels = $request->request->all('channels');

            $result = [];
            $count = 0;
            foreach ($channels as $channel) {
                if ($count >= 20) {
                    break;
                }

                try {
                    $tickets_id = (int) ($channel['tickets_id'] ?? 0);
                    $room_type = (string) ($channel['room_type'] ?? '');
                    $after_id = (int) ($channel['after_id'] ?? 0);
                    $after_event_id = (string) ($channel['after_event_id'] ?? '');
                    $is_typing = (bool) ($channel['is_typing'] ?? false);
                    if ($tickets_id <= 0 || !in_array($room_type, ['public', 'internal'], true)) {
                        continue;
                    }

                    glpichat_assert_can_read_room($tickets_id, $room_type);
                    $rooms_id = glpichat_room_id($tickets_id, $room_type);
                    $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
                    if ($participants_id > 0) {
                        glpichat_update_participant_activity($participants_id, $is_typing);
                    }
                    $messages = glpichat_messages($rooms_id, $after_id);
                    $events = glpichat_events($rooms_id, $after_event_id);
                    $typing_names = glpichat_get_typing_participants($rooms_id, $participants_id);

                    $key = $tickets_id . ':' . $room_type;
                    $result[$key] = [
                        'rooms_id' => $rooms_id,
                        'tickets_id' => $tickets_id,
                        'room_type' => $room_type,
                        'messages' => $messages,
                        'events' => $events,
                        'ticket_status' => glpichat_ticket_status($tickets_id),
                        'last_message_id' => glpichat_room_last_message_id($rooms_id),
                        'last_event_id' => glpichat_room_last_event_id($rooms_id),
                        'participants_id' => $participants_id,
                        'unread_count' => glpichat_unread_count($rooms_id, $participants_id),
                        'typing_names' => $typing_names,
                    ];
                    $count++;
                } catch (RuntimeException $channel_error) {
                    continue;
                }
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
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
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

            glpichat_widget_readable_room_or_fail(
                $rooms_id,
                'glpichat_room_by_id',
                'glpichat_assert_can_read_room'
            );
            $participants_id = glpichat_participant_id_for_user($rooms_id, glpichat_current_user_id());
            if ($participants_id > 0) {
                glpichat_update_participant_activity($participants_id, false);
            }

            $events = [];
            if ($before_id === 0) {
                $events = glpichat_events($rooms_id, '');
            }

            return new JsonResponse([
                'messages' => glpichat_messages_before($rooms_id, $before_id, $limit),
                'events'   => $events,
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Widget/MarkRead', name: 'glpichat_widget_mark_read', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
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

            glpichat_widget_readable_room_or_fail(
                $rooms_id,
                'glpichat_room_by_id',
                'glpichat_assert_can_read_room'
            );
            $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
            $persisted_last_read_message_id = glpichat_mark_read($rooms_id, $participants_id, $last_read_message_id);

            return new JsonResponse([
                'ok' => true,
                'last_read_message_id' => $persisted_last_read_message_id,
                'unread_count' => glpichat_unread_count($rooms_id, $participants_id),
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Widget/Leave', name: 'glpichat_widget_leave', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function leave(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $users_id = glpichat_current_user_id();

            $rooms_id = $request->request->getInt('rooms_id');
            if ($rooms_id <= 0) {
                throw new RuntimeException('Invalid room parameter.');
            }

            glpichat_widget_readable_room_or_fail(
                $rooms_id,
                'glpichat_room_by_id',
                'glpichat_assert_can_read_room'
            );
            glpichat_disconnect_user_participant($rooms_id, $users_id);

            return new JsonResponse([
                'ok' => true,
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
