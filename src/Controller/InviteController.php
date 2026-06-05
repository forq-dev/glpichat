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

use function glpichat_create_invite_use_case;
use function glpichat_assert_can_create_invites;
use function glpichat_require_logged_csrf;

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/flows/invite_flow.php';

final class InviteController extends AbstractController
{
    #[Route('/Invite', name: 'glpichat_invite', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function __invoke(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);

            $tickets_id = $request->request->getInt('tickets_id');
            $guest_name = $request->request->getString('guest_name');
            $guest_email = $request->request->getString('guest_email');

            $invite = glpichat_create_invite_use_case(
                $tickets_id,
                $guest_name,
                $guest_email,
                (string) $GLOBALS['CFG_GLPI']['url_base'],
                'glpichat_assert_can_create_invites',
                'glpichat_room_id',
                static fn(): string => bin2hex(random_bytes(32)),
                'glpichat_current_user_id',
                'glpichat_insert_invite',
                'glpichat_record_event',
                GLPICHAT_INVITE_TTL_SECONDS
            );
            $invite['_glpi_csrf_token'] = Session::getNewCSRFToken();
            return new JsonResponse($invite);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
