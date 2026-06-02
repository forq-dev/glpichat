<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonGLPI;
use Session;
use Ticket;
use Html;

use function glpichat_can_read_ticket;
use function glpichat_can_view_audit;

final class TicketTab extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'GLPI Chat';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate || !$item instanceof Ticket || !$item->getID()) {
            return '';
        }

        $tickets_id = (int) $item->getID();
        $tabs = [];

        if (glpichat_can_read_ticket($tickets_id)) {
            $tabs[1] = self::createTabEntry('Gestão de invites', 0, self::class, 'ti ti-ticket');
        }

        if (glpichat_can_view_audit($tickets_id)) {
            $tabs[2] = self::createTabEntry('Auditoria do Chat', 0, self::class, 'ti ti-history');
        }

        return $tabs;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || !$item->getID()) {
            return true;
        }

        $tickets_id = (int) $item->getID();

        if ($tabnum == 1) {
            if (!glpichat_can_read_ticket($tickets_id)) {
                return true;
            }

            $csrf = Session::getNewCSRFToken();
            echo '<div class="card">';
            echo '<div class="card-header"><strong>Gestão de invites</strong></div>';
            echo '<div class="card-body">';
            echo '<p>Use este painel para criar, revogar e reemitir invites do chamado.</p>';
            echo '<div class="glpichat-invite-management" data-ticket-id="' . htmlescape((string) $tickets_id) . '" data-csrf="' . htmlescape($csrf) . '"></div>';
            echo '</div>';
            echo '</div>';

            echo Html::scriptBlock("
                (function () {
                    var root = document.querySelector('.glpichat-invite-management[data-ticket-id=\\\"" . (int) $tickets_id . "\\\"]');
                    if (!root) {
                        return;
                    }

                    if (window.glpiChatMountInviteManagement) {
                        window.glpiChatMountInviteManagement(root);
                        return;
                    }

                    var csrf = root.dataset.csrf || '';
                    var ticketId = Number(root.dataset.ticketId || '0');
                    if (!ticketId) {
                        return;
                    }

                    var fetchJson = async function (url, options) {
                        var response = await fetch(url, options || { credentials: 'same-origin' });
                        var data = await response.json();
                        if (!response.ok) {
                            throw new Error(data.error || 'Request failed');
                        }
                        if (data._glpi_csrf_token) {
                            csrf = data._glpi_csrf_token;
                        }
                        return data;
                    };

                    var postForm = async function (url, values) {
                        var form = new FormData();
                        Object.keys(values).forEach(function (key) {
                            form.set(key, String(values[key]));
                        });
                        form.set('_glpi_csrf_token', csrf);
                        return fetchJson(url, {
                            method: 'POST',
                            body: form,
                            credentials: 'same-origin'
                        });
                    };

                    var render = async function () {
                        var listData = await fetchJson('/plugins/glpichat/Invite/List?tickets_id=' + ticketId, { credentials: 'same-origin' });

                        root.innerHTML = '';
                        var createForm = document.createElement('form');
                        createForm.className = 'glpichat-invite';
                        createForm.innerHTML = ''
                            + '<input class=\"form-control\" type=\"text\" name=\"guest_name\" placeholder=\"Nome do convidado\" required>'\n                            + '<input class=\"form-control\" type=\"email\" name=\"guest_email\" placeholder=\"Email do convidado\">'\n                            + '<button class=\"btn btn-secondary\" type=\"submit\">Criar invite</button>'\n                            + '<div data-role=\"invite-result\"></div>';

                        createForm.addEventListener('submit', async function (event) {
                            event.preventDefault();
                            var guestName = createForm.querySelector('[name=\\'guest_name\\']').value.trim();
                            var guestEmail = createForm.querySelector('[name=\\'guest_email\\']').value.trim();
                            if (!guestName) {
                                return;
                            }
                            var created = await postForm('/plugins/glpichat/Invite', {
                                tickets_id: ticketId,
                                guest_name: guestName,
                                guest_email: guestEmail
                            });
                            createForm.querySelector('[data-role=\\'invite-result\\']').textContent = 'Link: ' + created.url;
                            await render();
                        });

                        var table = document.createElement('table');
                        table.className = 'table table-sm mt-3';
                        table.innerHTML = '<thead><tr><th>Convidado</th><th>Status</th><th>Expira</th><th>Acesso</th><th>Ações</th></tr></thead>';
                        var tbody = document.createElement('tbody');

                        (listData.invites || []).forEach(function (invite) {
                            var row = document.createElement('tr');
                            row.innerHTML = ''
                                + '<td>' + (invite.guest_name || '') + '</td>'\n                                + '<td>' + (invite.status || '') + '</td>'\n                                + '<td>' + (invite.expires_at || '') + '</td>'\n                                + '<td>' + (invite.was_accessed ? 'Sim' : 'Não') + '</td>'\n                                + '<td></td>';

                            var actionsCell = row.lastElementChild;
                            var revokeBtn = document.createElement('button');
                            revokeBtn.className = 'btn btn-sm btn-outline-danger me-1';
                            revokeBtn.type = 'button';
                            revokeBtn.textContent = 'Revogar';
                            revokeBtn.addEventListener('click', async function () {
                                await postForm('/plugins/glpichat/Invite/Revoke', { invite_id: invite.id });
                                await render();
                            });

                            var reissueBtn = document.createElement('button');
                            reissueBtn.className = 'btn btn-sm btn-outline-primary';
                            reissueBtn.type = 'button';
                            reissueBtn.textContent = 'Reemitir';
                            reissueBtn.addEventListener('click', async function () {
                                var result = await postForm('/plugins/glpichat/Invite/Reissue', { invite_id: invite.id });
                                window.alert('Novo link: ' + result.url);
                                await render();
                            });

                            actionsCell.appendChild(revokeBtn);
                            actionsCell.appendChild(reissueBtn);
                            tbody.appendChild(row);
                        });

                        table.appendChild(tbody);
                        root.appendChild(createForm);
                        root.appendChild(table);
                    };

                    render().catch(function (error) {
                        root.innerHTML = '<div class=\"alert alert-warning\">Falha ao carregar gestão de invites: ' + error.message + '</div>';
                    });
                })();
            ");
        } else if ($tabnum == 2) {
            if (!glpichat_can_view_audit($tickets_id)) {
                return true;
            }

            self::displayAuditLogs($tickets_id);
        }

        return true;
    }

    private static function displayAuditLogs(int $tickets_id): void
    {
        global $DB;

        $rows = $DB->request([
            'FROM' => 'glpi_plugin_glpichat_auditlogs',
            'WHERE' => ['tickets_id' => $tickets_id],
            'ORDER' => 'id DESC',
        ]);

        echo '<div class="card">';
        echo '<div class="card-header"><strong>Logs de Auditoria do Chat</strong></div>';
        echo '<div class="card-body">';

        if (count($rows) === 0) {
            echo '<div class="alert alert-info">Nenhum evento registrado na auditoria deste chamado.</div>';
            echo '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-striped table-hover table-sm">';
        echo '<thead><tr>';
        echo '<th>Data/Hora</th>';
        echo '<th>Ação</th>';
        echo '<th>Ator</th>';
        echo '<th>Detalhes</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($rows as $row) {
            $payload = [];
            if (!empty($row['payload_json'])) {
                try {
                    $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\Exception $e) {
                    $payload = [];
                }
            }

            // Date formatting
            $date = Html::convDateTime($row['date_creation']);

            // Event action friendly name
            $action = $row['action'];
            $action_label = $action;
            $details = '';

            switch ($action) {
                case 'message_created':
                    $action_label = 'Mensagem Enviada';
                    $room_type = $payload['room_type'] ?? 'public';
                    $details = 'Mensagem adicionada no canal <strong>' . ($room_type === 'internal' ? 'interno' : 'público') . '</strong>.';
                    break;
                case 'guest_invited':
                    $action_label = 'Convidado Convidado';
                    $guest_name = $payload['guest_name'] ?? '';
                    $details = 'Convite criado para o convidado <strong>' . htmlescape($guest_name) . '</strong>.';
                    break;
                case 'guest_joined':
                    $action_label = 'Convidado Entrou';
                    $guest_name = $payload['guest_name'] ?? 'Convidado';
                    $details = 'O convidado <strong>' . htmlescape($guest_name) . '</strong> acessou o link e entrou no chat.';
                    break;
                case 'invite_revoked':
                    $action_label = 'Convite Cancelado';
                    $invite_id = $payload['invite_id'] ?? '';
                    $details = 'Convite ID <strong>#' . htmlescape((string) $invite_id) . '</strong> foi revogado.';
                    break;
                case 'invite_reissued':
                    $action_label = 'Convite Reemitido';
                    $invite_id = $payload['invite_id'] ?? '';
                    $expires = isset($payload['expires_at']) ? Html::convDateTime($payload['expires_at']) : '';
                    $details = 'Convite ID <strong>#' . htmlescape((string) $invite_id) . '</strong> foi reemitido. Nova expiração: ' . $expires . '.';
                    break;
            }

            // Actor identification
            $actor = '';
            if ($row['actor_type'] === 'user') {
                $user_id = (int)$row['users_id'];
                if ($user_id > 0) {
                    $actor = getUserName($user_id) ?: 'Usuário #' . $user_id;
                } else {
                    $actor = 'Sistema';
                }
            } else if ($row['actor_type'] === 'guest') {
                $guest_name = $payload['guest_name'] ?? 'Convidado';
                $actor = htmlescape($guest_name) . ' (Externo)';
            } else {
                $actor = 'Desconhecido';
            }

            echo '<tr>';
            echo '<td>' . htmlescape($date) . '</td>';
            echo '<td>' . htmlescape($action_label) . '</td>';
            echo '<td>' . htmlescape($actor) . '</td>';
            echo '<td>' . $details . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
