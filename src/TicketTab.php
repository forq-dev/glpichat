<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonGLPI;
use Session;
use Ticket;
use Html;

use function glpichat_can_read_ticket;
use function glpichat_can_create_invites;
use function glpichat_can_update_invites;
use function glpichat_can_view_participants;
use function glpichat_can_view_audit;
use function glpichat_config_int;

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

        if (glpichat_can_view_participants($tickets_id)) {
            $tabs[1] = self::createTabEntry('Gestão de invites', 0, self::class, 'ti ti-message-circle');
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
            if (!glpichat_can_view_participants($tickets_id)) {
                return true;
            }

            $csrf = Session::getNewCSRFToken();
            $can_create_invites = glpichat_can_create_invites($tickets_id);
            $can_update_invites = glpichat_can_update_invites($tickets_id);
            $invite_poll_ms = glpichat_config_int('invite_poll_ms', 10000);
            echo '<div class="card">';
            echo '<div class="card-header"><strong>Gestão de invites</strong></div>';
            echo '<div class="card-body">';
            echo '<p>Use este painel para criar, revogar e reemitir invites do chamado.</p>';
            echo '<div class="glpichat-invite-management"'
                . ' data-ticket-id="' . htmlescape((string) $tickets_id) . '"'
                . ' data-csrf="' . htmlescape($csrf) . '"'
                . ' data-can-create="' . ($can_create_invites ? '1' : '0') . '"'
                . ' data-can-update="' . ($can_update_invites ? '1' : '0') . '"'
                . '></div>';
            echo '</div>';
            echo '</div>';            echo Html::scriptBlock("
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
                    var canCreate = root.dataset.canCreate === '1';
                    var canUpdate = root.dataset.canUpdate === '1';
                    if (!ticketId) {
                        return;
                    }

                    var escapeHtml = function (value) {
                        return String(value || '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/\"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    };

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

                    var normalizedInviteText = function (value) {
                        return String(value || '').trim().toLowerCase();
                    };

                    var inviteFlagEnabled = function (value) {
                        return value === true || value === 1 || value === '1';
                    };

                    var deriveInviteState = function (invite) {
                        var inviteStatus = normalizedInviteText(invite.invite_status || invite.status);
                        var leftAt = String(invite.left_at || invite.session_left_at || invite.session_ended_at || '').trim();
                        var explicitSessionStatus = normalizedInviteText(invite.session_status);
                        var fallbackSessionStatus = inviteFlagEnabled(invite.is_session_active)
                            ? 'active'
                            : (leftAt !== '' ? normalizedInviteText(invite.session_end_reason || invite.session_reason || 'disconnected') : '');
                        var sessionStatus = explicitSessionStatus || fallbackSessionStatus;
                        var hasSessionTelemetry = sessionStatus !== '' || leftAt !== '' || inviteFlagEnabled(invite.is_session_active);
                        var inviteLabel = 'Status desconhecido';
                        var sessionLabel = 'Sem sessão';
                        var accessLabel = invite.was_accessed ? 'Sim' : 'Não';

                        if (inviteStatus === 'active') {
                            inviteLabel = 'Convite ativo';
                        } else if (inviteStatus === 'expired') {
                            inviteLabel = 'Convite expirado';
                        } else if (inviteStatus === 'revoked') {
                            inviteLabel = 'Convite revogado';
                        } else if (inviteStatus === 'accepted') {
                            inviteLabel = 'Link consumido';
                        }

                        if (sessionStatus === 'active') {
                            sessionLabel = 'Sessão ativa';
                            accessLabel = 'Sessão ativa';
                        } else if (sessionStatus === 'timeout' || sessionStatus === 'expired') {
                            sessionLabel = 'Sessão expirada';
                            accessLabel = 'Encerrada por inatividade';
                        } else if (sessionStatus === 'revoked' || sessionStatus === 'disconnected' || sessionStatus === 'cancelled') {
                            sessionLabel = 'Sessão encerrada';
                            accessLabel = 'Encerrada manualmente';
                        } else if (inviteStatus === 'accepted') {
                            sessionLabel = hasSessionTelemetry ? 'Sessão encerrada' : 'Link consumido';
                        }

                        return {
                            inviteStatus: inviteStatus,
                            sessionStatus: sessionStatus,
                            inviteLabel: inviteLabel,
                            sessionLabel: sessionLabel,
                            accessLabel: accessLabel,
                            canDisconnect: sessionStatus === 'active' || (inviteStatus === 'accepted' && !hasSessionTelemetry),
                            canRevoke: inviteStatus === 'active',
                            canReissue: inviteStatus === 'active' || inviteStatus === 'expired' || inviteStatus === 'revoked'
                        };
                    };

                    // Initialize static DOM structure
                    root.innerHTML = '';
                    var tbody = document.createElement('tbody');

                    if (canCreate) {
                        var createForm = document.createElement('div');
                        createForm.className = 'glpichat-invite mb-3';
                        createForm.innerHTML = ''
                            + '<input class=\"form-control mb-2\" type=\"text\" name=\"guest_name\" placeholder=\"Nome do convidado\" required>'
                            + '<input class=\"form-control mb-2\" type=\"email\" name=\"guest_email\" placeholder=\"Email do convidado\">'
                            + '<button class=\"btn btn-secondary\" type=\"button\" data-role=\"submit-btn\">Criar invite</button>'
                            + '<div class=\"mt-2\" data-role=\"invite-result\"></div>';

                        var submitInvite = async function () {
                            var guestName = createForm.querySelector('[name=\\'guest_name\\']').value.trim();
                            var guestEmail = createForm.querySelector('[name=\\'guest_email\\']').value.trim();
                            var resultDiv = createForm.querySelector('[data-role=\\'invite-result\\']');
                            if (!guestName) {
                                return;
                            }
                            var btn = createForm.querySelector('[data-role=\\'submit-btn\\']');
                            btn.disabled = true;
                            try {
                                var created = await postForm('/plugins/glpichat/Invite', {
                                    tickets_id: ticketId,
                                    guest_name: guestName,
                                    guest_email: guestEmail
                                });
                                resultDiv.innerHTML = '<span class=\"text-success fw-bold\">Link gerado:</span> <a href=\"' + escapeHtml(created.url) + '\" target=\"_blank\">' + escapeHtml(created.url) + '</a>';
                                createForm.querySelector('[name=\\'guest_name\\']').value = '';
                                createForm.querySelector('[name=\\'guest_email\\']').value = '';
                                await render();
                            } catch (e) {
                                resultDiv.innerHTML = '<span class=\"text-danger\">Erro: ' + escapeHtml(e.message) + '</span>';
                            } finally {
                                btn.disabled = false;
                            }
                        };

                        createForm.querySelector('[data-role=\"submit-btn\"]').addEventListener('click', submitInvite);

                        var handleKeyPress = function (e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                submitInvite();
                            }
                        };
                        createForm.querySelector('[name=\\'guest_name\\']').addEventListener('keydown', handleKeyPress);
                        createForm.querySelector('[name=\\'guest_email\\']').addEventListener('keydown', handleKeyPress);
                        root.appendChild(createForm);
                    }

                    var table = document.createElement('table');
                    table.className = 'table table-sm mt-3';
                    table.innerHTML = '<thead><tr><th>Convidado</th><th>Convite</th><th>Sessão</th><th>Expira</th><th>Acesso</th><th>Ações</th></tr></thead>';
                    table.appendChild(tbody);
                    root.appendChild(table);

                    var render = async function () {
                        var listData = await fetchJson('/plugins/glpichat/Invite/List?tickets_id=' + ticketId, { credentials: 'same-origin' });

                        // Update only the tbody to prevent flickering and input loss
                        tbody.innerHTML = '';

                        if (!listData.invites || listData.invites.length === 0) {
                            var emptyRow = document.createElement('tr');
                            emptyRow.innerHTML = '<td colspan=\"6\" class=\"text-center text-muted\">Nenhum convite encontrado.</td>';
                            tbody.appendChild(emptyRow);
                            return;
                        }

                        listData.invites.forEach(function (invite) {
                            var inviteState = deriveInviteState(invite);
                            var row = document.createElement('tr');
                            row.innerHTML = ''
                                + '<td>' + escapeHtml(invite.guest_name || '') + '</td>'
                                + '<td>' + escapeHtml(inviteState.inviteLabel) + '</td>'
                                + '<td>' + escapeHtml(inviteState.sessionLabel) + '</td>'
                                + '<td>' + escapeHtml(invite.expires_at || '') + '</td>'
                                + '<td>' + escapeHtml(inviteState.accessLabel) + '</td>'
                                + '<td></td>';

                            var actionsCell = row.lastElementChild;

                            if (canUpdate && inviteState.canReissue) {
                                var reissueBtn = document.createElement('button');
                                reissueBtn.className = 'btn btn-sm btn-outline-primary me-1';
                                reissueBtn.type = 'button';
                                reissueBtn.textContent = 'Reemitir';
                                reissueBtn.addEventListener('click', async function () {
                                    try {
                                        var result = await postForm('/plugins/glpichat/Invite/Reissue', { invite_id: invite.id });
                                        window.alert('Novo link: ' + result.url);
                                        await render();
                                    } catch (e) {
                                        window.alert('Erro: ' + e.message);
                                    }
                                });
                                actionsCell.appendChild(reissueBtn);
                            }

                            if (canUpdate && inviteState.canRevoke) {
                                var revokeBtn = document.createElement('button');
                                revokeBtn.className = 'btn btn-sm btn-outline-danger me-1';
                                revokeBtn.type = 'button';
                                revokeBtn.textContent = 'Cancelar convite';
                                revokeBtn.addEventListener('click', async function () {
                                    if (window.confirm('Tem certeza que deseja cancelar este convite?')) {
                                        await postForm('/plugins/glpichat/Invite/Revoke', { invite_id: invite.id });
                                        await render();
                                    }
                                });
                                actionsCell.appendChild(revokeBtn);
                            }

                            if (canUpdate && inviteState.canDisconnect) {
                                var disconnectBtn = document.createElement('button');
                                disconnectBtn.className = 'btn btn-sm btn-outline-warning';
                                disconnectBtn.type = 'button';
                                disconnectBtn.textContent = 'Encerrar chat';
                                disconnectBtn.addEventListener('click', async function () {
                                    if (window.confirm('Tem certeza que deseja encerrar o chat ativo?')) {
                                        await postForm('/plugins/glpichat/Invite/Revoke', { invite_id: invite.id });
                                        await render();
                                    }
                                });
                                actionsCell.appendChild(disconnectBtn);
                            }

                            tbody.appendChild(row);
                        });
                    };

                    if (typeof root._glpiChatInviteTimer === 'number') {
                        window.clearInterval(root._glpiChatInviteTimer);
                    }

                    // Visibility tracking using IntersectionObserver
                    var isVisible = true;
                    if ('IntersectionObserver' in window) {
                        isVisible = false; // default to false until observed
                        var observer = new IntersectionObserver(function (entries) {
                            isVisible = entries[0].isIntersecting;
                            if (isVisible) {
                                render().catch(function () {});
                            }
                        }, { root: null, threshold: 0 });
                        observer.observe(root);
                    } else {
                        // Fallback
                        isVisible = true;
                    }

                    render().then(function () {
                        root._glpiChatInviteTimer = window.setInterval(function () {
                            if (!document.body.contains(root)) {
                                window.clearInterval(root._glpiChatInviteTimer);
                                delete root._glpiChatInviteTimer;
                                return;
                            }
                            if (document.hidden || !isVisible) {
                                return;
                            }
                            // Extra check for tab visibility fallback
                            var tabPane = root.closest('.tab-pane');
                            if (tabPane && !tabPane.classList.contains('active')) {
                                return;
                            }
                            
                            render().catch(function () {});
                        }, " . $invite_poll_ms . ");
                    }).catch(function (error) {
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
                    $action_label = 'Convite Criado';
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
                case 'guest_left':
                    $action_label = 'Convidado Saiu';
                    $guest_name = $payload['guest_name'] ?? 'Convidado';
                    $details = 'O convidado <strong>' . htmlescape($guest_name) . '</strong> saiu do chat.';
                    break;
                case 'guest_left_timeout':
                    $action_label = 'Sessão Expirada';
                    $guest_name = $payload['guest_name'] ?? 'Convidado';
                    $details = 'A sessão do convidado <strong>' . htmlescape($guest_name) . '</strong> expirou por inatividade.';
                    break;
                case 'user_joined':
                    $action_label = 'Técnico Entrou';
                    $user_name = $payload['user_name'] ?? 'Técnico';
                    $details = 'O técnico <strong>' . htmlescape($user_name) . '</strong> entrou no chat.';
                    break;
                case 'user_left':
                    $action_label = 'Técnico Saiu';
                    $user_name = $payload['user_name'] ?? 'Técnico';
                    $details = 'O técnico <strong>' . htmlescape($user_name) . '</strong> saiu do chat.';
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
            } else if ($row['actor_type'] === 'system') {
                $actor = 'Sistema';
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
