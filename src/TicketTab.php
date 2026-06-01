<?php

declare(strict_types=1);

namespace GlpiPlugin\Glpichat;

use CommonGLPI;
use Session;
use Ticket;

use function glpichat_can_read_ticket;

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

        if (!glpichat_can_read_ticket((int) $item->getID())) {
            return '';
        }

        return self::createTabEntry('Gestao de invites', 0, self::class, 'ti ti-ticket');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || !$item->getID()) {
            return true;
        }

        $tickets_id = (int) $item->getID();
        if (!glpichat_can_read_ticket($tickets_id)) {
            return true;
        }

        $csrf = Session::getNewCSRFToken();
        echo '<div class="card">';
        echo '<div class="card-header"><strong>Gestao de invites</strong></div>';
        echo '<div class="card-body">';
        echo '<p>Use este painel para criar, revogar e reemitir invites do chamado.</p>';
        echo '<div class="glpichat-invite-management" data-ticket-id="' . htmlescape((string) $tickets_id) . '" data-csrf="' . htmlescape($csrf) . '"></div>';
        echo '</div>';
        echo '</div>';

        echo \Html::scriptBlock("\n            (function () {\n                var root = document.querySelector('.glpichat-invite-management[data-ticket-id=\\\"" . (int) $tickets_id . "\\\"]');\n                if (!root) {\n                    return;\n                }\n\n                if (window.glpiChatMountInviteManagement) {\n                    window.glpiChatMountInviteManagement(root);\n                    return;\n                }\n\n                var csrf = root.dataset.csrf || '';\n                var ticketId = Number(root.dataset.ticketId || '0');\n                if (!ticketId) {\n                    return;\n                }\n\n                var fetchJson = async function (url, options) {\n                    var response = await fetch(url, options || { credentials: 'same-origin' });\n                    var data = await response.json();\n                    if (!response.ok) {\n                        throw new Error(data.error || 'Request failed');\n                    }\n                    if (data._glpi_csrf_token) {\n                        csrf = data._glpi_csrf_token;\n                    }\n                    return data;\n                };\n\n                var postForm = async function (url, values) {\n                    var form = new FormData();\n                    Object.keys(values).forEach(function (key) {\n                        form.set(key, String(values[key]));\n                    });\n                    form.set('_glpi_csrf_token', csrf);\n                    return fetchJson(url, {\n                        method: 'POST',\n                        body: form,\n                        credentials: 'same-origin'\n                    });\n                };\n\n                var render = async function () {\n                    var listData = await fetchJson('/plugins/glpichat/Invite/List?tickets_id=' + ticketId, { credentials: 'same-origin' });\n\n                    root.innerHTML = '';\n                    var createForm = document.createElement('form');\n                    createForm.className = 'glpichat-invite';\n                    createForm.innerHTML = ''\n                        + '<input class=\"form-control\" type=\"text\" name=\"guest_name\" placeholder=\"Nome do convidado\" required>'\n                        + '<input class=\"form-control\" type=\"email\" name=\"guest_email\" placeholder=\"Email do convidado\">'\n                        + '<button class=\"btn btn-secondary\" type=\"submit\">Criar invite</button>'\n                        + '<div data-role=\"invite-result\"></div>';\n\n                    createForm.addEventListener('submit', async function (event) {\n                        event.preventDefault();\n                        var guestName = createForm.querySelector('[name=\\'guest_name\\']').value.trim();\n                        var guestEmail = createForm.querySelector('[name=\\'guest_email\\']').value.trim();\n                        if (!guestName) {\n                            return;\n                        }\n                        var created = await postForm('/plugins/glpichat/Invite', {\n                            tickets_id: ticketId,\n                            guest_name: guestName,\n                            guest_email: guestEmail\n                        });\n                        createForm.querySelector('[data-role=\\'invite-result\\']').textContent = 'Link: ' + created.url;\n                        await render();\n                    });\n\n                    var table = document.createElement('table');\n                    table.className = 'table table-sm mt-3';\n                    table.innerHTML = '<thead><tr><th>Convidado</th><th>Status</th><th>Expira</th><th>Acesso</th><th>Ações</th></tr></thead>';\n                    var tbody = document.createElement('tbody');\n\n                    (listData.invites || []).forEach(function (invite) {\n                        var row = document.createElement('tr');\n                        row.innerHTML = ''\n                            + '<td>' + (invite.guest_name || '') + '</td>'\n                            + '<td>' + (invite.status || '') + '</td>'\n                            + '<td>' + (invite.expires_at || '') + '</td>'\n                            + '<td>' + (invite.was_accessed ? 'Sim' : 'Não') + '</td>'\n                            + '<td></td>';\n\n                        var actionsCell = row.lastElementChild;\n                        var revokeBtn = document.createElement('button');\n                        revokeBtn.className = 'btn btn-sm btn-outline-danger me-1';\n                        revokeBtn.type = 'button';\n                        revokeBtn.textContent = 'Revogar';\n                        revokeBtn.addEventListener('click', async function () {\n                            await postForm('/plugins/glpichat/Invite/Revoke', { invite_id: invite.id });\n                            await render();\n                        });\n\n                        var reissueBtn = document.createElement('button');\n                        reissueBtn.className = 'btn btn-sm btn-outline-primary';\n                        reissueBtn.type = 'button';\n                        reissueBtn.textContent = 'Reemitir';\n                        reissueBtn.addEventListener('click', async function () {\n                            var result = await postForm('/plugins/glpichat/Invite/Reissue', { invite_id: invite.id });\n                            window.alert('Novo link: ' + result.url);\n                            await render();\n                        });\n\n                        actionsCell.appendChild(revokeBtn);\n                        actionsCell.appendChild(reissueBtn);\n                        tbody.appendChild(row);\n                    });\n\n                    table.appendChild(tbody);\n                    root.appendChild(createForm);\n                    root.appendChild(table);\n                };\n\n                render().catch(function (error) {\n                    root.innerHTML = '<div class=\"alert alert-warning\">Falha ao carregar gestão de invites: ' + error.message + '</div>';\n                });\n            })();\n        ");

        return true;
    }
}
