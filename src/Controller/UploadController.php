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

use function glpichat_add_followup;
use function glpichat_add_message;
use function glpichat_assert_can_send;
use function glpichat_current_user_id;
use function glpichat_participant_id_for_user;
use function glpichat_require_logged_csrf;
use function glpichat_room_id;
use function glpichat_upload_document;

require_once dirname(__DIR__) . '/functions.php';

final class UploadController extends AbstractController
{
    #[Route('/Upload', name: 'glpichat_upload', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function __invoke(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);

            $tickets_id = $request->request->getInt('tickets_id');
            $room_type = $request->request->getString('room_type');
            $file = $request->files->get('file');

            if ($tickets_id <= 0 || !$file || !in_array($room_type, ['public', 'internal'], true)) {
                throw new RuntimeException('Invalid upload parameters.');
            }

            glpichat_assert_can_send($tickets_id, $room_type);
            $users_id = glpichat_current_user_id();
            $documents_id = glpichat_upload_document($tickets_id, $file, true);
            $content = 'Attachment added from GLPI Chat: ' . $file->getClientOriginalName();
            $followups_id = glpichat_add_followup($tickets_id, $users_id, $content, false);
            $rooms_id = glpichat_room_id($tickets_id, $room_type);
            $participants_id = glpichat_participant_id_for_user($rooms_id, $users_id);
            $message_id = glpichat_add_message($tickets_id, $room_type, 'user', $users_id, $participants_id, $content, $documents_id, $followups_id);

            return new JsonResponse([
                'message_id' => $message_id,
                'documents_id' => $documents_id,
                '_glpi_csrf_token' => \Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
