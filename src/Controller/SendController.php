<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_assert_can_send;
use function glpichat_current_user_id;
use function glpichat_message_by_id;
use function glpichat_send_message_use_case;
use function glpichat_participant_id_for_user;
use function glpichat_require_logged_csrf;
use function glpichat_room_id;

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/flows/message_flow.php';

final class SendController extends AbstractController
{
    #[Route('/Send', name: 'glpichat_send', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function __invoke(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);

            $tickets_id = $request->request->getInt('tickets_id');
            $room_type = $request->request->getString('room_type');
            $content = $request->request->getString('content');

            if ($tickets_id <= 0 || !in_array($room_type, ['public', 'internal'], true)) {
                throw new RuntimeException('Parâmetros inválidos.');
            }

            $message_id = glpichat_send_message_use_case(
                $tickets_id,
                $room_type,
                $content,
                'glpichat_assert_can_send',
                'glpichat_current_user_id',
                'glpichat_room_id',
                'glpichat_participant_id_for_user',
                'glpichat_add_followup',
                'glpichat_add_message'
            );

            return new JsonResponse([
                'message_id' => $message_id,
                'message' => glpichat_message_by_id($message_id),
                '_glpi_csrf_token' => \Session::getNewCSRFToken(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
