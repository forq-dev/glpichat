<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_accept_invite;
use function glpichat_add_followup;
use function glpichat_add_message;
use function glpichat_events;
use function glpichat_external_followup_user_id;
use function glpichat_guest_participant;
use function glpichat_messages;
use function glpichat_upload_document;

use const GLPICHAT_GUEST_COOKIE;

require_once dirname(__DIR__) . '/functions.php';

final class GuestController extends AbstractController
{
    #[Route('/Accept/{token}', name: 'glpichat_accept', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function accept(string $token): Response
    {
        try {
            $accepted = glpichat_accept_invite($token);
            $response = new Response($this->guestHtml((int) $accepted['tickets_id'], (int) $accepted['rooms_id'], (string) $accepted['guest_name']));
            $response->headers->setCookie(Cookie::create(GLPICHAT_GUEST_COOKIE, $accepted['session_token'])->withHttpOnly(true)->withSameSite('Strict'));
            return $response;
        } catch (RuntimeException $e) {
            return new Response(htmlescape($e->getMessage()), 400);
        }
    }

    #[Route('/Guest/Sync', name: 'glpichat_guest_sync', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function sync(Request $request): Response
    {
        try {
            $participant = glpichat_guest_participant($request->cookies->get(GLPICHAT_GUEST_COOKIE, ''));
            $rooms_id = (int) $participant['rooms_id'];

            return new JsonResponse([
                'messages' => glpichat_messages($rooms_id, $request->query->getInt('last_message_id')),
                'events' => glpichat_events($rooms_id, $request->query->getInt('last_event_id')),
            ]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Guest/Send', name: 'glpichat_guest_send', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function send(Request $request): Response
    {
        try {
            $participant = glpichat_guest_participant($request->cookies->get(GLPICHAT_GUEST_COOKIE, ''));
            $content = trim($request->request->getString('content'));
            if ($content === '') {
                throw new RuntimeException('Invalid message content.');
            }

            $followups_id = glpichat_add_followup(
                (int) $this->ticketIdForGuest($participant),
                glpichat_external_followup_user_id(),
                $content,
                false
            );
            $message_id = glpichat_add_message((int) $this->ticketIdForGuest($participant), 'public', 'guest', 0, (int) $participant['id'], $content, 0, $followups_id);

            return new JsonResponse(['message_id' => $message_id]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Guest/Upload', name: 'glpichat_guest_upload', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function upload(Request $request): Response
    {
        try {
            $participant = glpichat_guest_participant($request->cookies->get(GLPICHAT_GUEST_COOKIE, ''));
            $file = $request->files->get('file');
            if (!$file) {
                throw new RuntimeException('Invalid upload parameters.');
            }

            $tickets_id = (int) $this->ticketIdForGuest($participant);
            $documents_id = glpichat_upload_document($tickets_id, $file, true);
            $content = 'Attachment added from guest chat: ' . $file->getClientOriginalName();
            $followups_id = glpichat_add_followup(
                $tickets_id,
                glpichat_external_followup_user_id(),
                $content,
                false
            );
            $message_id = glpichat_add_message($tickets_id, 'public', 'guest', 0, (int) $participant['id'], $content, $documents_id, $followups_id);

            return new JsonResponse(['message_id' => $message_id, 'documents_id' => $documents_id]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function ticketIdForGuest(array $participant): int
    {
        global $DB;

        $room = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_rooms',
            'WHERE' => ['id' => (int) $participant['rooms_id']],
            'LIMIT' => 1,
        ])->current();

        if (!is_array($room)) {
            throw new RuntimeException('Guest room not found.');
        }

        return (int) $room['tickets_id'];
    }

    private function guestHtml(int $tickets_id, int $rooms_id, string $guest_name): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>GLPI Chat</title>'
            . '<link rel="stylesheet" href="../public/css/glpichat.css"></head><body>'
            . '<main class="glpichat glpichat-guest" data-ticket-id="' . htmlescape((string) $tickets_id) . '" data-room-id="' . htmlescape((string) $rooms_id) . '" data-guest="1">'
            . '<h1>GLPI Chat</h1><p>' . htmlescape($guest_name) . '</p>'
            . '<div class="glpichat-messages" data-role="messages"></div>'
            . '<form class="glpichat-send" data-role="guest-send-form">'
            . '<input id="guest-attach" class="glpichat-file-input" type="file" name="file">'
            . '<label for="guest-attach" class="glpichat-attach" title="Anexar arquivo">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
            . '</label>'
            . '<textarea name="content" placeholder="Escreva uma mensagem..." required></textarea>'
            . '<button type="submit" class="glpichat-send-btn" title="Enviar mensagem">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>'
            . '</button>'
            . '</form>'
            . '</main><script src="../public/js/glpichat.js"></script></body></html>';
    }
}
