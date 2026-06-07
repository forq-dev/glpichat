<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Http\RedirectResponse;
use Glpi\Security\Attribute\SecurityStrategy;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function glpichat_accept_invite;
use function glpichat_accept_invite_use_case;
use function glpichat_events;
use function glpichat_guest_leave_use_case;
use function glpichat_guest_send_message_use_case;
use function glpichat_guest_participant;
use function glpichat_guest_sync_use_case;
use function glpichat_guest_upload_message_use_case;
use function glpichat_messages;
use function glpichat_room_by_id;
use function glpichat_ticket_status;

use const GLPICHAT_GUEST_COOKIE;

require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/flows/guest_flow.php';

final class GuestController extends AbstractController
{
    #[Route('/Accept/{token}', name: 'glpichat_accept', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function accept(Request $request, string $token): Response
    {
        try {
            $accepted = glpichat_accept_invite_use_case(
                $token,
                static fn(string $token_hash): array => glpichat_accept_invite_lookup($token_hash),
                static fn(): string => bin2hex(random_bytes(32)),
                'glpichat_create_guest_participant',
                'glpichat_mark_invite_accepted',
                'glpichat_record_event',
                'glpichat_external_followup_user_id',
                'glpichat_add_followup',
                'glpichat_raise_guest_joined_notification_event'
            );
            global $CFG_GLPI;
            $url_base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
            $redirect = new RedirectResponse($url_base . '/plugins/glpichat/Guest');
            $redirect->headers->setCookie(
                Cookie::create(GLPICHAT_GUEST_COOKIE, $accepted['session_token'])
                    ->withHttpOnly(true)
                    ->withSameSite('Strict')
                    ->withSecure($request->isSecure())
            );
            return $redirect;
        } catch (RuntimeException $e) {
            return new Response(htmlescape($e->getMessage()), 400);
        }
    }

    #[Route('/Guest', name: 'glpichat_guest_page', methods: ['GET'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function page(Request $request): Response
    {
        $session_token = $request->cookies->get(GLPICHAT_GUEST_COOKIE, '');
        if ($session_token === '') {
            return new Response(htmlescape('Invalid or missing guest session.'), 400);
        }
        try {
            $participant = glpichat_guest_participant($session_token);
            $room = glpichat_room_by_id((int) $participant['rooms_id']);
            $response = new Response($this->guestHtml((int) $room['tickets_id'], (int) $participant['rooms_id'], (string) $participant['guest_name']));
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; style-src 'self'; script-src 'self'");
            $response->headers->set('Referrer-Policy', 'no-referrer');
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
            $is_typing = $request->query->getBoolean('is_typing');
            $result = glpichat_guest_sync_use_case(
                $request->cookies->get(GLPICHAT_GUEST_COOKIE, ''),
                $request->query->getInt('last_message_id'),
                $request->query->getString('last_event_id'),
                $is_typing,
                'glpichat_guest_participant',
                'glpichat_room_by_id',
                'glpichat_messages',
                'glpichat_events',
                'glpichat_ticket_status',
                'glpichat_update_participant_activity',
                'glpichat_get_typing_participants'
            );

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/Guest/Leave', name: 'glpichat_guest_leave', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function leave(Request $request): Response
    {
        $session_token = $request->cookies->get(GLPICHAT_GUEST_COOKIE, '');
        if ($session_token === '') {
            return new Response('', 204);
        }

        try {
            glpichat_guest_leave_use_case(
                $session_token,
                date('Y-m-d H:i:s'),
                'glpichat_guest_participant',
                'glpichat_disconnect_participant'
            );
        } catch (RuntimeException $error) {
            return new Response('', 204);
        }

        return new Response('', 204);
    }

    #[Route('/Guest/Send', name: 'glpichat_guest_send', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function send(Request $request): Response
    {
        try {
            $message_id = glpichat_guest_send_message_use_case(
                $request->cookies->get(GLPICHAT_GUEST_COOKIE, ''),
                $request->request->getString('content'),
                'glpichat_guest_participant',
                'glpichat_room_by_id',
                'glpichat_assert_ticket_open_for_chat',
                'glpichat_external_followup_user_id',
                'glpichat_add_followup',
                'glpichat_add_message'
            );

            return new JsonResponse(['message_id' => $message_id]);
        } catch (\Throwable $e) {
            $status = $e->getCode() === 429 ? 429 : 400;
            return new JsonResponse(['error' => $e->getMessage()], $status);
        }
    }

    #[Route('/Guest/Upload', name: 'glpichat_guest_upload', methods: ['POST'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function upload(Request $request): Response
    {
        try {
            $result = glpichat_guest_upload_message_use_case(
                $request->cookies->get(GLPICHAT_GUEST_COOKIE, ''),
                $request->files->get('file'),
                'glpichat_guest_participant',
                'glpichat_room_by_id',
                'glpichat_assert_ticket_open_for_chat',
                'glpichat_upload_document',
                'glpichat_external_followup_user_id',
                'glpichat_add_followup',
                'glpichat_add_message'
            );

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            $status = $e->getCode() === 429 ? 429 : 400;
            return new JsonResponse(['error' => $e->getMessage()], $status);
        }
    }

    private function guestHtml(int $tickets_id, int $rooms_id, string $guest_name): string
    {
        global $CFG_GLPI;
        $url_path = parse_url($CFG_GLPI['url_base'] ?? '', PHP_URL_PATH) ?? '';
        $url_path = rtrim($url_path, '/');

        $plugin_dir = dirname(__DIR__, 2);
        $css_version = file_exists($plugin_dir . '/public/css/glpichat.css') ? filemtime($plugin_dir . '/public/css/glpichat.css') : time();
        $js_version = file_exists($plugin_dir . '/public/js/glpichat-guest.js') ? filemtime($plugin_dir . '/public/js/glpichat-guest.js') : time();

        return '<!doctype html><html><head><meta charset="utf-8"><title>GLPI Chat</title>'
            . '<link rel="stylesheet" href="' . $url_path . '/plugins/glpichat/public/css/glpichat.css?v=' . $css_version . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '</head><body class="glpichat-guest-page">'
            . '<main class="glpichat glpichat-guest" data-ticket-id="' . htmlescape((string) $tickets_id) . '" data-room-id="' . htmlescape((string) $rooms_id) . '" data-guest="1">'
            . '<div class="glpichat-chatbox-panel">'
            . '  <div class="glpichat-dropzone-overlay">'
            . '    <span class="glpichat-dropzone-icon">'
            . '      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
            . '    </span>'
            . '    <span>Solte o arquivo para enviar</span>'
            . '  </div>'
            . '  <div class="glpichat-window-head">'
            . '    <strong>Chamado #' . htmlescape((string) $tickets_id) . '</strong>'
            . '    <span class="glpichat-guest-badge">' . htmlescape($guest_name) . '</span>'
            . '  </div>'
            . '  <div class="glpichat-messages" data-role="messages"></div>'
            . '  <div class="glpichat-typing-indicator" data-role="typing-indicator" hidden></div>'
            . '  <form class="glpichat-send" data-role="guest-send-form">'
            . '    <input id="guest-attach" class="glpichat-file-input" type="file" name="file">'
            . '    <label for="guest-attach" class="glpichat-attach" title="Anexar arquivo">'
            . '      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>'
            . '    </label>'
            . '    <textarea name="content" placeholder="Escreva uma mensagem..." required></textarea>'
            . '    <button type="submit" class="glpichat-send-btn" title="Enviar mensagem">'
            . '      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>'
            . '    </button>'
            . '  </form>'
            . '</div>'
            . '</main><script src="' . $url_path . '/plugins/glpichat/public/js/glpichat-guest.js?v=' . $js_version . '"></script></body></html>';
    }
}
