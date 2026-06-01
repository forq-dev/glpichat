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

use function glpichat_create_invite;
use function glpichat_require_logged_csrf;

require_once dirname(__DIR__) . '/functions.php';

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
            $guest_name = trim($request->request->getString('guest_name'));
            $guest_email = trim($request->request->getString('guest_email'));

            if ($tickets_id <= 0 || $guest_name === '') {
                throw new RuntimeException('Invalid invite parameters.');
            }

            $invite = glpichat_create_invite($tickets_id, $guest_name, $guest_email);
            $invite['_glpi_csrf_token'] = \Session::getNewCSRFToken();
            return new JsonResponse($invite);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
