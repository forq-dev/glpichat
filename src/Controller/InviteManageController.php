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

use function glpichat_list_invites;
use function glpichat_reissue_invite;
use function glpichat_require_logged_csrf;
use function glpichat_revoke_invite;

require_once dirname(__DIR__) . '/functions.php';

final class InviteManageController extends AbstractController
{
    #[Route('/Invite/List', name: 'glpichat_invite_list', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function list(Request $request): Response
    {
        try {
            Session::checkLoginUser();
            $tickets_id = $request->query->getInt('tickets_id');
            if ($tickets_id <= 0) {
                throw new RuntimeException('Invalid invite list parameters.');
            }

            return new JsonResponse([
                'invites' => glpichat_list_invites($tickets_id),
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
                throw new RuntimeException('Invalid invite revoke parameters.');
            }

            glpichat_revoke_invite($invite_id);

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
                throw new RuntimeException('Invalid invite reissue parameters.');
            }

            $invite = glpichat_reissue_invite($invite_id);
            $invite['_glpi_csrf_token'] = Session::getNewCSRFToken();
            return new JsonResponse($invite);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
