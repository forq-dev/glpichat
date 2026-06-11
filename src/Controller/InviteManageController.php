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

use function glpichat_list_invites_use_case;
use function glpichat_reissue_invite_use_case;
use function glpichat_require_logged_csrf;
use function glpichat_revoke_invite_use_case;
use function glpichat_assert_can_update_invites;

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/flows/invite_flow.php';

final class InviteManageController extends AbstractController
{
    #[Route('/Invite/List', name: 'glpichat_invite_list', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function list(Request $request): Response
    {
        try {
            Session::checkLoginUser();
            $tickets_id = $request->query->getInt('tickets_id');
            return new JsonResponse([
                'invites' => glpichat_list_invites_use_case(
                    $tickets_id,
                    'glpichat_assert_can_read_ticket',
                    'glpichat_assert_can_view_participants',
                    'glpichat_fetch_invites_for_ticket',
                    'glpichat_user_display_name',
                    time()
                ),
                '_glpi_csrf_token' => Session::getNewCSRFToken(),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Invite/Revoke', name: 'glpichat_invite_revoke', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function revoke(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $invite_id = $request->request->getInt('invite_id');
            if ($invite_id <= 0) {
                throw new RuntimeException('Parâmetros de revogação de convite inválidos.');
            }

            glpichat_revoke_invite_use_case(
                $invite_id,
                'glpichat_find_invite_by_id',
                'glpichat_assert_can_update_invites',
                'glpichat_current_user_id',
                'glpichat_mark_invite_revoked',
                'glpichat_record_event',
                'glpichat_disconnect_participant',
                (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'))
            );

            return new JsonResponse(['ok' => true, '_glpi_csrf_token' => Session::getNewCSRFToken()]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Invite/Reissue', name: 'glpichat_invite_reissue', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function reissue(Request $request): Response
    {
        try {
            $input = $request->request->all();
            glpichat_require_logged_csrf($input);
            $invite_id = $request->request->getInt('invite_id');
            if ($invite_id <= 0) {
                throw new RuntimeException('Parâmetros de reemissão de convite inválidos.');
            }

            $invite = glpichat_reissue_invite_use_case(
                $invite_id,
                (string) $GLOBALS['CFG_GLPI']['url_base'],
                'glpichat_find_invite_by_id',
                'glpichat_assert_can_update_invites',
                static fn(): string => bin2hex(random_bytes(32)),
                'glpichat_current_user_id',
                'glpichat_update_invite_reissue',
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
