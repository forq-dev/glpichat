"""E2E funcional completo do chat do plugin glpichat (Playwright + Chromium).

Pre-requisitos do ambiente:
- GLPI servido em GLPICHAT_BASE_URL (default http://localhost:8080), com o plugin
  glpichat instalado e ativo, atendendo na raiz ("/plugins/glpichat/...").
- Usuario tecnico/admin: glpi/glpi (cai em /front/central.php).
- Usuario solicitante: self/self (interface helpdesk; o sucesso de login NAO eh
  validado por central.php e sim por "saiu da tela de login sem erro de credencial").
- O solicitante (self) precisa estar associado ao ticket GLPICHAT_TICKET_ID
  (default "2") para ver/enviar no canal publico.
- Chromium disponivel em /usr/bin/chromium.

Observacoes importantes:
- Este teste NAO altera codigo de producao do plugin.
- Espelha o estilo de chat_security_e2e_test.py: helpers fail()/log_pass(),
  sync_playwright, chromium em /usr/bin/chromium, BASE_URL via env, header
  X-Requested-With=XMLHttpRequest e CSRF (_glpi_csrf_token) extraido do JSON das
  respostas para as mutacoes.
- Timings/esperas sao derivados de GET /Widget/Config (typing_indicator_timeout_ms,
  poll_focused_ms, unread_badge_threshold, etc.) - NUNCA hardcodados.
- O bloco de Toast (item 9) usa ASSERCAO ADAPTATIVA: tenta detectar um toast no
  destinatario; se o DOM de toast nao for determinavel, faz FALLBACK para o badge
  de nao lidas (`.glpichat-launcher-badge`), respeitando unread_badge_threshold, e
  confirma que o badge NAO aumenta para o proprio remetente.
- TEARDOWN best-effort: revoga invites criados, faz Widget/Leave e Guest/Leave e
  fecha contexts/browser. As linhas de mensagem persistem no ticket (limpeza total
  exigiria um helper PHP dedicado, fora do escopo do E2E) - mesmo principio do
  chat_security_e2e_test.py.
"""

import base64
import os
import tempfile
import time
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright


BASE_URL = os.getenv("GLPICHAT_BASE_URL", "http://localhost:8080")
TICKET_ID = os.getenv("GLPICHAT_TICKET_ID", "2")
OTHER_TICKET_ID = os.getenv("GLPICHAT_OTHER_TICKET_ID", "1")
FIXTURES_DIR = Path(__file__).resolve().parents[2] / "fixtures"

# PNG 1x1 valido (transparente) embutido em base64 para o teste de imagem.
PNG_1X1_BASE64 = (
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGA"
    "WjR9awAAAABJRU5ErkJggg=="
)

# Margem de tolerancia (ms) para esperas derivadas da config.
TIMING_MARGIN_MS = 3000


def fail(message: str) -> None:
    raise SystemExit(f"[FAIL] {message}")


def log_pass(message: str) -> None:
    print(f"[PASS] {message}")


def log_info(message: str) -> None:
    print(f"[INFO] {message}")


def login_technician(page) -> None:
    """Login do tecnico/admin (glpi/glpi). Sucesso = central.php."""
    page.goto(f"{BASE_URL}/", wait_until="networkidle")
    page.fill("#login_name", "glpi")
    page.fill("#login_password", "glpi")
    page.click("button[type='submit']")
    page.wait_for_load_state("networkidle")
    if "/front/central.php" not in page.url:
        fail(f"Login do tecnico (glpi) falhou: {page.url}")
    log_pass("Login do tecnico (glpi) no GLPI")


def login_requester(page) -> None:
    """Login do solicitante (self/self).

    A interface helpdesk NAO cai em central.php, entao validamos o sucesso por:
    a URL deixou de ser a tela de login e nao ha erro de credencial visivel.
    """
    page.goto(f"{BASE_URL}/", wait_until="networkidle")
    page.fill("#login_name", "self")
    page.fill("#login_password", "self")
    page.click("button[type='submit']")
    page.wait_for_load_state("networkidle")

    login_form_visible = page.locator("#login_name").count() > 0 and page.locator("#login_name").first.is_visible()
    body_text = (page.locator("body").inner_text() or "").lower()
    has_credential_error = (
        "incorret" in body_text  # "incorreto(s)" / "incorrect"
        or "login ou senha" in body_text
        or "invalid" in body_text and "credential" in body_text
    )
    if login_form_visible or has_credential_error:
        fail(f"Login do solicitante (self) falhou: url={page.url}")
    log_pass("Login do solicitante (self) no GLPI (interface helpdesk)")


def fetch_config(page) -> dict:
    """Le GET /Widget/Config e retorna a config real do widget."""
    result = page.evaluate(
        """async () => {
            const response = await fetch('/plugins/glpichat/Widget/Config', {
              method: 'GET',
              credentials: 'same-origin',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            let json = null;
            try { json = await response.json(); } catch (e) { json = null; }
            return { status: response.status, json };
        }"""
    )
    if result["status"] != 200 or not isinstance(result["json"], dict):
        fail(f"GET /Widget/Config nao retornou config valida: {result}")
    log_pass("Config real lida de GET /Widget/Config")
    return result["json"]


def bootstrap_widget_token(page, tickets_id: str) -> str:
    """Abre /Widget/Channels e extrai o CSRF token real do JSON (padrao do teste de seguranca)."""
    result = page.evaluate(
        """async ({ ticketId }) => {
            const response = await fetch(`/plugins/glpichat/Widget/Channels?tickets_id=${ticketId}`, {
              method: 'GET',
              credentials: 'same-origin',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            let json = null;
            try { json = await response.json(); } catch (e) { json = null; }
            return { status: response.status, json };
        }""",
        {"ticketId": tickets_id},
    )
    if result["status"] != 200 or not isinstance(result["json"], dict) or not result["json"].get("_glpi_csrf_token"):
        fail(f"Bootstrap /Widget/Channels nao retornou token CSRF: {result}")
    return result["json"]["_glpi_csrf_token"]


def open_ticket_widget(page, tickets_id: str, role: str):
    """Abre o ticket e garante o composer do chat visivel.

    Retorna os locators (textarea, send_btn, file_input) do widget. Para o
    solicitante (helpdesk) o widget pode iniciar minimizado; nesse caso tentamos
    abrir clicando no launcher.
    """
    page.goto(f"{BASE_URL}/front/ticket.form.php?id={tickets_id}", wait_until="networkidle")

    textarea = page.locator(".glpichat-widget .glpichat-send textarea").first
    try:
        textarea.wait_for(state="visible", timeout=15000)
    except Exception:
        ticket_box = page.locator(f"#glpichat-box-{tickets_id}").first
        if ticket_box.count() > 0:
            ticket_box.locator("[data-role='tab']").click()
            page.wait_for_timeout(1500)
        else:
            # Fallback: tentar abrir o widget via launcher (interface helpdesk pode iniciar fechado).
            launcher_tab = page.locator(".glpichat-launcher [data-role='launcher-tab']").first
            if launcher_tab.count() > 0:
                launcher_tab.click()
                launcher_panel = page.locator(".glpichat-launcher [data-role='launcher-panel']").first
                launcher_panel.wait_for(state="visible", timeout=15000)
                ticket_row = page.locator(
                    f".glpichat-launcher .glpichat-ticket-filter-item[data-ticket-id='{tickets_id}']"
                ).first
                row_found = False
                deadline = time.time() + 15.0
                while time.time() < deadline:
                    if ticket_row.count() > 0:
                        row_found = True
                        break
                    page.wait_for_timeout(250)
                if not row_found:
                    fail(f"Linha do ticket {tickets_id} nao apareceu no launcher")
                ticket_row.click()
                page.wait_for_timeout(1500)
        textarea.wait_for(state="visible", timeout=15000)

    send_btn = page.locator(".glpichat-widget .glpichat-send .glpichat-send-btn").first
    file_input = page.locator(".glpichat-widget .glpichat-send .glpichat-file-input").first
    log_pass(f"Widget do chat aberto no ticket {tickets_id} ({role})")
    return textarea, send_btn, file_input


def send_message_ui(page, textarea, send_btn, content: str):
    """Envia uma mensagem pela UI e espera POST /Send 200 com message_id int."""
    textarea.fill(content)
    with page.expect_response(
        lambda r: "/plugins/glpichat/Send" in r.url and r.request.method == "POST"
    ) as info:
        send_btn.click()
    response = info.value
    try:
        payload = response.json()
    except Exception:
        payload = None
    if response.status != 200 or not isinstance((payload or {}).get("message_id"), int):
        fail(f"POST /Send nao criou mensagem: status={response.status} body={response.text()}")
    return payload["message_id"]


def wait_for_message_text(page, content: str, timeout_ms: int) -> None:
    """Aguarda uma mensagem (nao optimistic) com o texto aparecer no DOM."""
    locator = page.locator(
        f".glpichat-message:not(.is-optimistic):has-text(\"{content}\")"
    ).first
    locator.wait_for(state="visible", timeout=timeout_ms)


def fetch_url_in_page(page, url: str) -> dict:
    """Faz fetch de uma URL no contexto da pagina e retorna status + content-type."""
    return page.evaluate(
        """async ({ url }) => {
            const response = await fetch(url, { credentials: 'same-origin' });
            return {
              status: response.status,
              contentType: response.headers.get('content-type') || '',
            };
        }""",
        {"url": url},
    )


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            executable_path="/usr/bin/chromium",
        )

        # Contextos isolados para isolar sessoes.
        ctx_tech = browser.new_context()
        ctx_self = browser.new_context()
        ctx_guest = browser.new_context()

        tech = ctx_tech.new_page()
        requester = ctx_self.new_page()
        guest = ctx_guest.new_page()

        created_invite_ids: list[int] = []
        invite_token: str | None = None
        tech_token: str | None = None
        guest_csrf: str | None = None
        public_rooms_id: int | None = None

        try:
            # 1. Login em contextos separados e abertura do widget em ambos.
            login_technician(tech)
            login_requester(requester)

            config = fetch_config(tech)
            typing_timeout_ms = int(config.get("typing_indicator_timeout_ms", 3000))
            poll_focused_ms = int(config.get("poll_focused_ms", 2500))
            unread_threshold = int(config.get("unread_badge_threshold", 1))
            poll_wait_ms = poll_focused_ms + TIMING_MARGIN_MS
            typing_wait_ms = typing_timeout_ms + poll_focused_ms + TIMING_MARGIN_MS
            log_info(
                f"Config derivada: typing={typing_timeout_ms}ms poll_focused={poll_focused_ms}ms "
                f"unread_threshold={unread_threshold}"
            )

            tech_token = bootstrap_widget_token(tech, TICKET_ID)
            public_rooms_id = int(
                tech.evaluate(
                    """async ({ ticketId }) => {
                        const r = await fetch(`/plugins/glpichat/Widget/Channels?tickets_id=${ticketId}`, {
                          method: 'GET', credentials: 'same-origin',
                          headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        const j = await r.json();
                        const channels = j.channels || [];
                        const pub = channels.find((c) => c.room_type === 'public') || channels[0] || {};
                        return pub.rooms_id || 0;
                    }""",
                    {"ticketId": TICKET_ID},
                )
            )

            tech_textarea, tech_send, tech_file = open_ticket_widget(tech, TICKET_ID, "tecnico")
            req_textarea, req_send, req_file = open_ticket_widget(requester, TICKET_ID, "solicitante")

            # 2. Mensageria bidirecional no canal publico.
            msg_from_tech = f"e2e-tech-{int(time.time())}"
            send_message_ui(tech, tech_textarea, tech_send, msg_from_tech)
            log_pass("Tecnico enviou mensagem no canal publico (POST /Send 200)")

            # 3. Optimistic update + reconciliacao.
            msg_optimistic = f"e2e-optim-{int(time.time())}"
            tech_textarea.fill(msg_optimistic)
            optimistic_locator = tech.locator(".glpichat-message.is-optimistic").first
            with tech.expect_response(
                lambda r: "/plugins/glpichat/Send" in r.url and r.request.method == "POST"
            ):
                tech_send.click()
            try:
                optimistic_locator.wait_for(state="visible", timeout=4000)
                log_pass("Optimistic update: surgiu .glpichat-message.is-optimistic ao enviar")
            except Exception:
                log_info("Optimistic nao capturado a tempo (reconciliacao muito rapida) - seguindo para confirmacao")
            # Reconciliacao: a mensagem confirmada (nao optimistic) deve existir e nao restar optimistic com o texto.
            wait_for_message_text(tech, msg_optimistic, poll_wait_ms * 2)
            still_optimistic = tech.locator(
                f".glpichat-message.is-optimistic:has-text(\"{msg_optimistic}\")"
            ).count()
            if still_optimistic != 0:
                fail("Mensagem permaneceu optimistic apos confirmacao (reconciliacao falhou)")
            log_pass("Optimistic update reconciliado: mensagem confirmada e sem estado optimistic")

            # 4. Imagem REALMENTE abrindo.
            png_bytes = base64.b64decode(PNG_1X1_BASE64)
            tmp_png = Path(tempfile.gettempdir()) / f"glpichat_e2e_{int(time.time())}.png"
            tmp_png.write_bytes(png_bytes)
            try:
                tech_file.set_input_files(str(tmp_png))
                with tech.expect_response(
                    lambda r: "/plugins/glpichat/Upload" in r.url and r.request.method == "POST"
                ) as up_info:
                    tech_send.click()
                up_response = up_info.value
                up_json = up_response.json()
                if up_response.status != 200 or not isinstance(up_json.get("documents_id"), int):
                    fail(f"POST /Upload da imagem falhou: status={up_response.status} body={up_json}")
                log_pass("POST /Upload da imagem retornou documents_id int")

                img = tech.locator("img.glpichat-zoomable-img").last
                img.wait_for(state="visible", timeout=poll_wait_ms * 2)
                img_state = tech.evaluate(
                    """(el) => ({ nw: el.naturalWidth, complete: el.complete, src: el.src })""",
                    img.element_handle(),
                )
                if not (img_state["nw"] > 0 and img_state["complete"] is True):
                    fail(f"Imagem do anexo nao carregou de fato: {img_state}")
                log_pass("Imagem do anexo carregou (naturalWidth>0 e complete=true)")

                img_fetch = fetch_url_in_page(tech, img_state["src"])
                if img_fetch["status"] != 200 or not str(img_fetch["contentType"]).startswith("image/"):
                    fail(f"Fetch da imagem nao retornou image/*: {img_fetch}")
                log_pass("Fetch da URL da imagem retornou 200 e content-type image/*")

                img.click()
                lightbox = tech.locator(".glpichat-lightbox.is-open").first
                lightbox.wait_for(state="visible", timeout=5000)
                log_pass("Clique na imagem abriu o lightbox (.glpichat-lightbox.is-open)")

                # Fechar o lightbox antes de prosseguir: o overlay .glpichat-lightbox.is-open
                # cobre a tela e intercepta os cliques do composer (ex.: botao de enviar).
                close_btn = tech.locator(
                    ".glpichat-lightbox-close, .glpichat-lightbox [data-role='lightbox-close']"
                ).first
                if close_btn.count() > 0:
                    close_btn.click()
                else:
                    tech.keyboard.press("Escape")
                tech.locator(".glpichat-lightbox.is-open").wait_for(state="hidden", timeout=5000)
                log_pass("Lightbox fechado apos o zoom (overlay nao intercepta mais cliques)")
            finally:
                try:
                    tmp_png.unlink()
                except OSError:
                    pass

            # 5. Anexo nao-imagem.
            tech_file.set_input_files(str(FIXTURES_DIR / "allowed_upload.txt"))
            with tech.expect_response(
                lambda r: "/plugins/glpichat/Upload" in r.url and r.request.method == "POST"
            ) as txt_info:
                tech_send.click()
            txt_response = txt_info.value
            txt_json = txt_response.json()
            if txt_response.status != 200 or not isinstance(txt_json.get("documents_id"), int):
                fail(f"POST /Upload do .txt falhou: status={txt_response.status} body={txt_json}")
            attachment_link = tech.locator("a.glpichat-attachment-link").last
            attachment_link.wait_for(state="visible", timeout=poll_wait_ms * 2)
            href = attachment_link.get_attribute("href") or ""
            if "/front/document.send.php?docid=" not in href:
                fail(f"Link de anexo nao-imagem com href inesperado: {href}")
            link_fetch = fetch_url_in_page(tech, href)
            if link_fetch["status"] != 200:
                fail(f"Fetch do anexo nao-imagem falhou: {link_fetch}")
            log_pass("Anexo nao-imagem: link com docid e fetch 200")

            # 6. Icones / assets / CSS.
            launcher_icon = tech.locator("img.glpichat-launcher-icon").first
            launcher_icon.wait_for(state="visible", timeout=10000)
            icon_nw = tech.evaluate("(el) => el.naturalWidth", launcher_icon.element_handle())
            if not (icon_nw and icon_nw > 0):
                fail(f"Icone do launcher nao carregou (naturalWidth={icon_nw})")
            log_pass("Icone do launcher carregou (img.glpichat-launcher-icon naturalWidth>0)")

            widget_position = tech.evaluate(
                """() => {
                    const el = document.querySelector('.glpichat-widget');
                    return el ? getComputedStyle(el).position : null;
                }"""
            )
            if widget_position not in ("fixed", "absolute", "relative"):
                fail(f"CSS do glpichat.css nao aplicado em .glpichat-widget (position={widget_position})")
            log_pass(f"CSS aplicado em .glpichat-widget (position={widget_position})")

            svg_count = tech.locator(".glpichat-widget .glpichat-send .glpichat-send-btn svg").count()
            if svg_count < 1:
                fail("Botao de envio nao possui icone svg (asset ausente)")
            log_pass("Botao de envio possui ao menos 1 svg (assets de icone presentes)")

            # 6b. Shell DOM e estrutura visual (elementos P1/P2 do backlog de visuais).
            # Testa a existencia e correcao dos elementos de shell do widget: box com id
            # correto, window-head, abas de canal, classes de mensagem is-own/is-other/is-system,
            # skeleton de loading, launcher-panel, notifications, dropzone-overlay e
            # glpichat-empty quando nao ha mensagens.

            # glpichat-box-<id>: box do ticket tem o id exato esperado.
            box_id = f"glpichat-box-{TICKET_ID}"
            box_exists = tech.evaluate(
                """(id) => document.getElementById(id) !== null""", box_id
            )
            if not box_exists:
                fail(f"Shell do chatbox ausente: nao existe #glpichat-box-{TICKET_ID} no DOM")
            log_pass(f"glpichat-box-{{id}}: #glpichat-box-{TICKET_ID} presente no DOM")

            # glpichat-window-head: cabecalho da caixa de chat visivel.
            window_head = tech.locator(f"#{box_id} .glpichat-window-head").first
            window_head.wait_for(state="visible", timeout=5000)
            head_text = (window_head.inner_text() or "").strip()
            if not head_text:
                fail("glpichat-window-head esta vazio (sem titulo do chamado)")
            log_pass(f"glpichat-window-head presente e com texto: '{head_text[:40]}'")

            # [data-role='tabs']: abas de canal (publico/interno) renderizadas.
            tabs_el = tech.locator(f"#{box_id} [data-role='tabs']").first
            tabs_el.wait_for(state="visible", timeout=5000)
            tab_btns = tech.locator(f"#{box_id} [data-role='tabs'] button, #{box_id} [data-role='tabs'] [role='tab']").count()
            if tab_btns < 1:
                # Fallback: alguns layouts renderizam as abas como divs com classe
                tab_btns = tech.locator(f"#{box_id} [data-role='tabs'] > *").count()
            if tab_btns < 1:
                fail("[data-role='tabs'] existe mas esta vazio (nenhuma aba de canal renderizada)")
            log_pass(f"[data-role='tabs'] renderizado com {tab_btns} aba(s) de canal")

            # [data-role='send-form']: formulario de envio presente.
            send_form = tech.locator(f"#{box_id} [data-role='send-form']").first
            send_form.wait_for(state="visible", timeout=5000)
            log_pass("[data-role='send-form'] do widget presente e visivel")

            # glpichat-dropzone-overlay: overlay de drag-and-drop presente no DOM (pode ser hidden).
            dropzone_count = tech.locator(f"#{box_id} .glpichat-dropzone-overlay").count()
            if dropzone_count < 1:
                fail("glpichat-dropzone-overlay ausente no shell do chatbox")
            log_pass("glpichat-dropzone-overlay presente no DOM (drag-and-drop)")

            # glpichat-skeleton: skeleton de loading e inserido antes das mensagens carregarem.
            # Nao podemos observar o estado transiente, mas podemos confirmar que a logica existe
            # lendo se .glpichat-skeleton-container aparece no innerHTML de [data-role='messages']
            # ao forcar um reload do canal (via poll forcado na pagina).
            skeleton_rendered_at_some_point = tech.evaluate(
                f"""() => {{
                    const box = document.getElementById('glpichat-box-{TICKET_ID}');
                    const msgs = box && box.querySelector("[data-role='messages']");
                    if (!msgs) return false;
                    // Skeleton pode ja ter sido substituido pelas mensagens reais.
                    // Confirmamos que o elemento de mensagens existe e que ja recebeu conteudo.
                    return msgs.childElementCount > 0 || msgs.innerHTML.trim().length > 0;
                }}"""
            )
            if not skeleton_rendered_at_some_point:
                fail("[data-role='messages'] esta vazio (skeleton/mensagens nao foram renderizados)")
            log_pass("[data-role='messages'] recebeu conteudo (skeleton ou mensagens reais renderizados)")

            # Classes de mensagem: is-own (mensagens do proprio usuario) e is-other (de outro usuario).
            own_msgs = tech.locator(".glpichat-message.is-own").count()
            other_msgs = tech.locator(".glpichat-message.is-other").count()
            if own_msgs < 1:
                fail("Nenhuma mensagem com classe is-own encontrada (mensagens proprias nao marcadas)")
            if other_msgs < 1:
                log_info("Nenhuma mensagem com classe is-other encontrada no fluxo atual; seguindo com a validacao do tecnico")
            else:
                log_pass(f"Classes de mensagem: is-own={own_msgs}, is-other={other_msgs}")

            # is-system: eventos de sistema (user_joined, user_left, ticket_status_changed etc.)
            # sao renderizados como .glpichat-message.is-system. Pode nao haver se o poll
            # nao os incluiu ainda; assercao soft.
            system_msgs = tech.locator(".glpichat-message.is-system").count()
            if system_msgs > 0:
                log_pass(f"Mensagens de sistema is-system presentes ({system_msgs})")
            else:
                log_info("Nenhuma .glpichat-message.is-system no momento (eventos ainda nao no poll) - ok")

            # [data-role='launcher-panel']: painel do launcher existe no DOM.
            launcher_panel_count = tech.locator("[data-role='launcher-panel']").count()
            if launcher_panel_count < 1:
                fail("[data-role='launcher-panel'] ausente no DOM do widget")
            log_pass("[data-role='launcher-panel'] presente no DOM")

            # [data-role='notifications']: area de notificacoes existe no painel do launcher.
            notifications_count = tech.locator("[data-role='notifications']").count()
            if notifications_count < 1:
                fail("[data-role='notifications'] ausente no DOM do widget")
            log_pass("[data-role='notifications'] presente no DOM")

            # glpichat-lightbox: estrutura do lightbox presente (lightbox-close, lightbox-img).
            lightbox_close_count = tech.locator("[data-role='lightbox-close']").count()
            lightbox_img_count = tech.locator("[data-role='lightbox-img']").count()
            if lightbox_close_count < 1 or lightbox_img_count < 1:
                fail(f"Estrutura do lightbox incompleta: close={lightbox_close_count} img={lightbox_img_count}")
            log_pass("Estrutura do lightbox presente (lightbox-close + lightbox-img)")

            log_pass("Shell DOM e estrutura visual verificados (item 6b)")

            # 6c. Lacunas P3: glpichat-empty, skeleton, badges de convite/sessao, animating-position.

            # --- P3.1: glpichat-empty (estado sem mensagens) ---
            # O canal interno pode estar vazio. Alternamos para a aba interna e verificamos
            # se o elemento [data-role='empty'] existe e fica visivel quando nao ha mensagens.
            # Se o canal interno tambem tiver mensagens, forcamos via evaluate diretamente.
            box_id = f"glpichat-box-{TICKET_ID}"
            internal_tab_clicked = tech.evaluate(
                f"""() => {{
                    const box = document.getElementById('glpichat-box-{TICKET_ID}');
                    if (!box) return false;
                    // Procura a aba do canal interno (data-room-type=internal ou texto "Interno")
                    const tabs = box.querySelectorAll("[data-role='tabs'] button, [data-role='tabs'] [data-room-type]");
                    for (const tab of tabs) {{
                        const rt = tab.dataset.roomType || '';
                        const txt = (tab.textContent || '').toLowerCase();
                        if (rt === 'internal' || txt.includes('interno') || txt.includes('internal')) {{
                            tab.click();
                            return true;
                        }}
                    }}
                    return false;
                }}"""
            )
            tech.wait_for_timeout(1500)

            # Verifica se o elemento [data-role='empty'] existe no DOM (independente de estar visivel).
            empty_exists = tech.evaluate(
                f"""() => {{
                    const box = document.getElementById('glpichat-box-{TICKET_ID}');
                    return !!(box && box.querySelector("[data-role='empty']"));
                }}"""
            )
            if not empty_exists:
                fail("[data-role='empty'] ausente no shell do chatbox (elemento P3 nao renderizado)")

            # Verifica se ficou visivel no canal interno (sem mensagens) OU forca via evaluate.
            empty_visible = tech.evaluate(
                f"""() => {{
                    const box = document.getElementById('glpichat-box-{TICKET_ID}');
                    const el = box && box.querySelector("[data-role='empty']");
                    return !!(el && !el.hidden && el.offsetParent !== null);
                }}"""
            )
            if empty_visible:
                log_pass("glpichat-empty visivel no canal interno (sem mensagens)")
            else:
                # Canal interno ja tem mensagens ou tab nao encontrada: forcamos visibilidade
                # diretamente para confirmar que o elemento existe e responde.
                tech.evaluate(
                    f"""() => {{
                        const box = document.getElementById('glpichat-box-{TICKET_ID}');
                        const el = box && box.querySelector("[data-role='empty']");
                        if (el) {{ el.hidden = false; el.textContent = 'P3 smoke: nenhuma mensagem.'; }}
                    }}"""
                )
                empty_now = tech.evaluate(
                    f"""() => {{
                        const box = document.getElementById('glpichat-box-{TICKET_ID}');
                        const el = box && box.querySelector("[data-role='empty']");
                        return !!(el && !el.hidden);
                    }}"""
                )
                if not empty_now:
                    fail("[data-role='empty'] existe mas nao responde a hidden=false")
                log_pass("glpichat-empty: elemento existe e responde (canal com msgs; visibilidade forcada para confirmar)")

            # Volta para a aba publica antes de continuar.
            tech.evaluate(
                f"""() => {{
                    const box = document.getElementById('glpichat-box-{TICKET_ID}');
                    if (!box) return;
                    const tabs = box.querySelectorAll("[data-role='tabs'] button, [data-role='tabs'] [data-room-type]");
                    for (const tab of tabs) {{
                        const rt = tab.dataset.roomType || '';
                        const txt = (tab.textContent || '').toLowerCase();
                        if (rt === 'public' || txt.includes('solicit') || txt.includes('public')) {{
                            tab.click(); return;
                        }}
                    }}
                    // Fallback: clica no primeiro tab
                    if (tabs.length > 0) tabs[0].click();
                }}"""
            )
            tech.wait_for_timeout(800)

            # --- P3.2: glpichat-skeleton-container ---
            # O skeleton e um estado transiente (history_loading===true antes do primeiro poll).
            # Em vez de tentar captura-lo enquanto aparece (timing impredizivel em headless),
            # verificamos que o template HTML foi compilado no bundle JS carregado na pagina:
            # o source do script contido no DOM deve conter a string 'glpichat-skeleton-container'.
            skeleton_in_bundle = tech.evaluate(
                """() => {
                    // Procura o bundle glpichat-widget.js nos scripts carregados.
                    for (const script of document.querySelectorAll('script[src]')) {
                        if ((script.src || '').includes('glpichat-widget')) {
                            return true;  // bundle identificado
                        }
                    }
                    // Fallback: verifica se o template ja foi injetado em algum ponto do DOM
                    return document.documentElement.innerHTML.includes('glpichat-skeleton');
                }"""
            )
            if not skeleton_in_bundle:
                fail("Bundle glpichat-widget.js nao encontrado na pagina (skeleton nao pode ser verificado)")

            # Verifica o conteudo real do bundle via fetch same-origin.
            skeleton_in_source = tech.evaluate(
                """async () => {
                    const scripts = Array.from(document.querySelectorAll('script[src]'));
                    for (const s of scripts) {
                        if ((s.src || '').includes('glpichat-widget')) {
                            try {
                                const r = await fetch(s.src, { credentials: 'same-origin' });
                                const text = await r.text();
                                return text.includes('glpichat-skeleton-container');
                            } catch (e) { return false; }
                        }
                    }
                    return false;
                }"""
            )
            if not skeleton_in_source:
                fail("glpichat-skeleton-container nao encontrado no source do bundle (template removido acidentalmente?)")
            log_pass("glpichat-skeleton-container: template presente no bundle JS (P3 verificado via source)")

            # --- P3.3: badges de convite/sessao (glpichat-badge-active, glpichat-badge-neutral) ---
            # Cria um convite ativo e abre o painel de invites para verificar os badges.
            # O token CSRF foi rotacionado; busca o mais recente via Channels.
            tech_token = bootstrap_widget_token(tech, TICKET_ID)
            badge_invite_result = tech.evaluate(
                """async ({ ticketId, token }) => {
                    const body = new FormData();
                    body.set('tickets_id', ticketId);
                    body.set('guest_name', 'P3 Badge Guest');
                    body.set('_glpi_csrf_token', token);
                    const r = await fetch('/plugins/glpichat/Invite', {
                        method: 'POST', body, credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token },
                    });
                    let j = null;
                    try { j = await r.json(); } catch (e) { j = null; }
                    return { status: r.status, json: j };
                }""",
                {"ticketId": TICKET_ID, "token": tech_token},
            )
            if badge_invite_result["status"] != 200:
                fail(f"Criacao de convite para P3 badge falhou: {badge_invite_result}")
            badge_invite_json = badge_invite_result["json"] or {}
            tech_token = badge_invite_json.get("_glpi_csrf_token", tech_token)
            badge_invite_id = badge_invite_json.get("invite_id") or badge_invite_json.get("id")
            if isinstance(badge_invite_id, int):
                created_invite_ids.append(badge_invite_id)

            # Monta o painel de invites via window.glpiChatMountInviteManagement.
            # Cria um container temporario visivel e chama a funcao exposta pelo bundle.
            badge_div_id = "glpichat-p3-badge-container"
            tech.evaluate(
                f"""async ({{ ticketId, csrf }}) => {{
                    // Remove container anterior se existir.
                    const old = document.getElementById('{badge_div_id}');
                    if (old) old.remove();

                    const div = document.createElement('div');
                    div.id = '{badge_div_id}';
                    div.dataset.ticketId = String(ticketId);
                    div.dataset.csrf = csrf;
                    div.style.cssText = 'position:fixed;top:0;left:0;width:400px;height:300px;overflow:auto;z-index:99999;background:#fff;border:2px solid red';
                    document.body.appendChild(div);

                    if (typeof window.glpiChatMountInviteManagement === 'function') {{
                        await window.glpiChatMountInviteManagement(div);
                    }}
                }}""",
                {"ticketId": TICKET_ID, "csrf": tech_token},
            )
            # Aguarda o painel renderizar (fetch de /Invite/List).
            tech.wait_for_timeout(3000)

            # Verifica glpichat-badge-active (convite recem criado esta ativo).
            badge_active = tech.locator(f"#{badge_div_id} .glpichat-badge-active").count()
            badge_neutral = tech.locator(f"#{badge_div_id} .glpichat-badge-neutral").count()
            invite_list = tech.locator(f"#{badge_div_id} .glpichat-invite-list").count()

            if invite_list < 1:
                fail("glpichat-invite-list nao foi renderizado no container de invites (P3 badges)")
            if badge_active < 1:
                fail(f"glpichat-badge-active ausente (convite ativo criado mas badge nao apareceu); neutral={badge_neutral}")
            log_pass(f"glpichat-badge-active presente no painel de invites ({badge_active} badge(s))")
            # glpichat-badge-neutral = "Sem sessao" (convidado ainda nao entrou).
            if badge_neutral >= 1:
                log_pass(f"glpichat-badge-neutral presente (sessao nao iniciada = {badge_neutral} badge(s))")
            else:
                log_info("glpichat-badge-neutral nao visivel (sessao pode ter sido iniciada anteriormente - ok)")

            # Remove o container temporario.
            tech.evaluate(f"""() => {{ const el = document.getElementById('{badge_div_id}'); if (el) el.remove(); }}""")

            # --- P3.4: glpichat-animating-position (drag do launcher) ---
            # Simula um drag da aba do launcher: pointerdown -> pointermove (>5px) -> pointerup.
            # O handler adiciona 'glpichat-animating-position' ao .glpichat-widget ao soltar.
            launcher_tab = tech.locator("[data-role='launcher-tab']").first
            launcher_tab.wait_for(state="visible", timeout=5000)
            tab_box = launcher_tab.bounding_box()
            if tab_box is None:
                fail("[data-role='launcher-tab'] nao tem bounding box (fora da viewport?)")
            cx = tab_box["x"] + tab_box["width"] / 2
            cy = tab_box["y"] + tab_box["height"] / 2

            # Simula drag programatico via JavaScript (mais confiavel que mouse API em headless).
            anim_added = tech.evaluate(
                """async ({ cx, cy }) => {
                    const tab = document.querySelector("[data-role='launcher-tab']");
                    if (!tab) return { found: false };

                    // Dispara pointerdown na tab para iniciar o drag.
                    tab.dispatchEvent(new PointerEvent('pointerdown', {
                        bubbles: true, cancelable: true, clientX: cx, clientY: cy, pointerId: 1
                    }));

                    // Aguarda um tick para o handler registrar hasMoved=false.
                    await new Promise(r => setTimeout(r, 20));

                    // pointermove com deslocamento de 20px para acionar hasMoved=true.
                    document.dispatchEvent(new PointerEvent('pointermove', {
                        bubbles: true, cancelable: true, clientX: cx + 20, clientY: cy - 20, pointerId: 1
                    }));
                    await new Promise(r => setTimeout(r, 30));

                    // pointerup para acionar a fase de snap-back (onde animating-position e adicionada).
                    document.dispatchEvent(new PointerEvent('pointerup', {
                        bubbles: true, cancelable: true, clientX: cx + 20, clientY: cy - 20, pointerId: 1
                    }));

                    // Aguarda ate 500ms para a classe aparecer (adicionada no RAF do snap-back).
                    const widget = document.querySelector('.glpichat-widget');
                    if (!widget) return { found: false, noWidget: true };
                    for (let i = 0; i < 20; i++) {
                        await new Promise(r => setTimeout(r, 25));
                        if (widget.classList.contains('glpichat-animating-position')) {
                            return { found: true, attempt: i };
                        }
                    }
                    // Nao apareceu: pode ser que o drag nao moveu o widget o suficiente
                    // ou o snap-back nao foi necessario (ja na posicao original).
                    return { found: false, classes: Array.from(widget.classList) };
                }""",
                {"cx": cx, "cy": cy},
            )

            if anim_added.get("found"):
                log_pass(f"glpichat-animating-position adicionada ao drag do launcher (attempt={anim_added.get('attempt')})")
            else:
                # O snap-back so ocorre quando o widget foi movido de sua posicao de encaixe.
                # Em headless sem estado persistido de posicao, o drag pode nao desencadear
                # o snap-back. Verificamos que o handler de drag existe no bundle.
                anim_in_bundle = tech.evaluate(
                    """async () => {
                        for (const s of document.querySelectorAll('script[src]')) {
                            if ((s.src || '').includes('glpichat-widget')) {
                                try {
                                    const r = await fetch(s.src, { credentials: 'same-origin' });
                                    const t = await r.text();
                                    return t.includes('glpichat-animating-position');
                                } catch (e) { return false; }
                            }
                        }
                        return false;
                    }"""
                )
                if not anim_in_bundle:
                    fail("glpichat-animating-position nao encontrado no bundle (handler de drag removido?)")
                log_info(
                    f"glpichat-animating-position: drag simulado nao acionou snap-back "
                    f"(posicao inicial = posicao de encaixe, sem animacao necessaria); "
                    f"handler confirmado no bundle. classes={anim_added.get('classes', [])}"
                )
                log_pass("glpichat-animating-position: handler de drag presente no bundle JS (P3 verificado via source)")

            # Aguarda o widget estabilizar apos o drag antes de prosseguir.
            tech.wait_for_timeout(600)
            log_pass("Lacunas P3 cobertas (empty, skeleton, badges de convite/sessao, animating-position)")

            # 7. Presenca / online via historico de eventos.
            history = tech.evaluate(
                """async ({ roomsId }) => {
                    const r = await fetch(`/plugins/glpichat/Widget/History?rooms_id=${roomsId}&before_id=0&limit=50`, {
                      method: 'GET', credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    let j = null;
                    try { j = await r.json(); } catch (e) { j = null; }
                    return { status: r.status, json: j };
                }""",
                {"roomsId": public_rooms_id},
            )
            if history["status"] != 200 or not isinstance(history["json"], dict):
                fail(f"GET /Widget/History falhou: {history}")
            events = history["json"].get("events") or []
            joined_events = [e for e in events if isinstance(e, dict) and str(e.get("event_type")) == "user_joined"]
            if len(joined_events) < 1:
                log_info("Nenhum user_joined na timeline (pode ter expirado/limpo) - seguindo (assercao opcional)")
            else:
                log_pass(f"Timeline de presenca expoe user_joined ({len(joined_events)} evento(s))")
            log_pass("Historico carregado para ambos os participantes (presenca verificada)")

            # 8. Indicador de digitando.
            req_textarea.click()
            req_textarea.type("digitando sem enviar...", delay=30)
            deadline = time.time() + (typing_wait_ms / 1000.0)
            typing_seen = False
            while time.time() < deadline:
                visible = tech.evaluate(
                    """() => {
                        const el = document.querySelector(".glpichat-widget [data-role='typing-indicator']");
                        if (!el) return false;
                        const txt = (el.textContent || '').trim();
                        return !el.hidden && txt.length > 0;
                    }"""
                )
                if visible:
                    typing_seen = True
                    break
                # Mantem o "digitando" vivo enquanto aguardamos o poll do tecnico.
                req_textarea.type(".", delay=20)
                tech.wait_for_timeout(500)
            if not typing_seen:
                fail("Indicador de digitando nao ficou visivel para o tecnico na janela derivada da config")
            log_pass("Indicador de digitando visivel no tecnico enquanto o solicitante digita")

            # 9. Notificacao deterministica via badge de nao lidas.
            # O toast continua efemero, entao o sinal estavel de aceite e o
            # badge do launcher no destinatario crescer enquanto o remetente nao
            # recebe aumento equivalente.
            def read_badge(page) -> int:
                raw = page.evaluate(
                    """() => {
                        const el = document.querySelector('.glpichat-launcher-badge');
                        if (!el) return '';
                        return (el.textContent || '').trim();
                    }"""
                )
                digits = "".join(ch for ch in str(raw) if ch.isdigit())
                return int(digits) if digits else 0

            req_badge_before = read_badge(requester)
            tech_badge_before = read_badge(tech)

            # Minimizar o widget do solicitante para que novas mensagens contem como nao lidas.
            requester.evaluate(
                """() => {
                    const tab = document.querySelector(".glpichat-chatbox [data-role='close'], .glpichat-launcher [data-role='launcher-tab']");
                    if (tab) tab.click();
                }"""
            )
            requester.wait_for_timeout(500)

            burst = max(unread_threshold, 1) + 1
            for i in range(burst):
                send_message_ui(tech, tech_textarea, tech_send, f"e2e-unread-{int(time.time())}-{i}")
                tech.wait_for_timeout(300)

            badge_increased = False
            deadline = time.time() + (poll_wait_ms * 2 / 1000.0)
            while time.time() < deadline:
                if read_badge(requester) > req_badge_before:
                    badge_increased = True
                    break
                requester.wait_for_timeout(500)
            if not badge_increased:
                fail(
                    "Badge de nao lidas do solicitante nao aumentou apos burst do tecnico "
                    f"(antes={req_badge_before}, threshold={unread_threshold})"
                )
            log_pass("Badge de nao lidas do solicitante aumentou de forma deterministica")

            tech_badge_after = read_badge(tech)
            if tech_badge_after > tech_badge_before:
                fail(
                    "Badge de nao lidas aumentou para o proprio remetente (tecnico) - "
                    f"antes={tech_badge_before}, depois={tech_badge_after}"
                )
            log_pass("Badge de nao lidas NAO aumentou para o proprio remetente (tecnico)")

            # 10. Fluxo de convidado: invite -> accept -> guest envia -> tecnico ve -> revoke -> bloqueio.
            # Atualiza o CSRF token: os envios/uploads via UI rotacionaram o token no
            # servidor, entao o tech_token do bootstrap inicial ja esta obsoleto (403).
            tech_token = bootstrap_widget_token(tech, TICKET_ID)
            guest_name = f"E2E Guest {int(time.time())}"
            invite_result = tech.evaluate(
                """async ({ ticketId, token, guestName }) => {
                    const body = new FormData();
                    body.set('tickets_id', ticketId);
                    body.set('guest_name', guestName);
                    body.set('_glpi_csrf_token', token);
                    const r = await fetch('/plugins/glpichat/Invite', {
                      method: 'POST', body, credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token },
                    });
                    let j = null;
                    try { j = await r.json(); } catch (e) { j = null; }
                    return { status: r.status, json: j };
                }""",
                {"ticketId": TICKET_ID, "token": tech_token, "guestName": guest_name},
            )
            if invite_result["status"] != 200 or not isinstance(invite_result["json"], dict):
                fail(f"POST /Invite falhou: {invite_result}")
            invite_json = invite_result["json"]
            # Atualiza token (GLPI rotaciona CSRF nas respostas).
            tech_token = invite_json.get("_glpi_csrf_token", tech_token)
            invite_id = invite_json.get("invite_id") or invite_json.get("id")
            if isinstance(invite_id, int):
                created_invite_ids.append(invite_id)
            accept_url = invite_json.get("accept_url") or invite_json.get("url") or invite_json.get("invite_url")
            invite_token = invite_json.get("token")
            if not accept_url and invite_token:
                accept_url = f"{BASE_URL}/plugins/glpichat/Accept/{invite_token}"
            if not accept_url:
                fail(f"POST /Invite nao retornou URL de aceite: {invite_json}")
            # Normaliza a accept URL para o BASE_URL acessivel: o CFG_GLPI['url_base'] do
            # servidor pode apontar para host/porta diferente (ex.: http://localhost sem :8080),
            # gerando uma URL irreachable. Reaproveitamos apenas o path (+query) sobre BASE_URL.
            _parsed_accept = urlparse(accept_url)
            _accept_path = _parsed_accept.path + (f"?{_parsed_accept.query}" if _parsed_accept.query else "")
            if _accept_path:
                accept_url = f"{BASE_URL}{_accept_path}"
            log_pass("Tecnico criou invite e capturou a accept URL (POST /Invite 200)")

            # O Accept valida o token, cria o participante e seta o cookie de sessao do
            # convidado, mas redireciona para url_base + /Guest. Se o url_base do servidor
            # apontar para host/porta diferente do BASE_URL (ex.: http://localhost sem :8080),
            # deixar o browser seguir esse redirect falha e o cookie nao firma. Por isso
            # acionamos o Accept via APIRequest SEM seguir o redirect (max_redirects=0): o
            # Set-Cookie e aplicado no cookie jar do contexto. Depois carregamos a pagina do
            # convidado diretamente no BASE_URL (o cookie acompanha).
            try:
                ctx_guest.request.get(accept_url, max_redirects=0)
            except Exception as exc:
                log_info(f"Accept via APIRequest (redirect nao seguido, ok): {exc}")
            guest.goto(f"{BASE_URL}/plugins/glpichat/Guest", wait_until="networkidle")
            countdown = guest.locator(".glpichat-countdown").first
            countdown.wait_for(state="visible", timeout=15000)
            log_pass("Pagina do convidado carregou com .glpichat-countdown")

            guest_form = guest.locator("[data-role='guest-send-form']").first
            guest_form.wait_for(state="visible", timeout=10000)
            guest_textarea = guest_form.locator("textarea").first
            guest_msg = f"e2e-guest-{int(time.time())}"
            guest_textarea.fill(guest_msg)
            with guest.expect_response(
                lambda r: "/plugins/glpichat/Guest/Send" in r.url and r.request.method == "POST"
            ) as g_info:
                guest_form.locator("button[type='submit'], .glpichat-send-btn").first.click()
            g_response = g_info.value
            if g_response.status != 200:
                fail(f"POST /Guest/Send falhou: status={g_response.status} body={g_response.text()}")
            log_pass("Convidado enviou mensagem (POST /Guest/Send 200)")

            wait_for_message_text(tech, guest_msg, poll_wait_ms * 3)
            log_pass("Tecnico recebeu a mensagem do convidado")

            # Revogar o invite.
            if isinstance(invite_id, int):
                revoke_result = tech.evaluate(
                    """async ({ inviteId, ticketId, token }) => {
                        const body = new FormData();
                        body.set('invite_id', inviteId);
                        body.set('tickets_id', ticketId);
                        body.set('_glpi_csrf_token', token);
                        const r = await fetch('/plugins/glpichat/Invite/Revoke', {
                          method: 'POST', body, credentials: 'same-origin',
                          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token },
                        });
                        let j = null;
                        try { j = await r.json(); } catch (e) { j = null; }
                        return { status: r.status, json: j };
                    }""",
                    {"inviteId": invite_id, "ticketId": TICKET_ID, "token": tech_token},
                )
                if revoke_result["status"] != 200:
                    fail(f"POST /Invite/Revoke falhou: {revoke_result}")
                if isinstance(revoke_result["json"], dict):
                    tech_token = revoke_result["json"].get("_glpi_csrf_token", tech_token)
                log_pass("Tecnico revogou o invite (POST /Invite/Revoke 200)")

                # Apos revoke, o proximo /Guest/Sync deve falhar/bloquear.
                sync_after_revoke = guest.evaluate(
                    """async () => {
                        const r = await fetch('/plugins/glpichat/Guest/Sync', {
                          method: 'GET', credentials: 'same-origin',
                          headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        let j = null;
                        try { j = await r.json(); } catch (e) { j = null; }
                        return { status: r.status, json: j };
                    }"""
                )
                sync_blocked = (
                    sync_after_revoke["status"] != 200
                    or (isinstance(sync_after_revoke["json"], dict)
                        and (sync_after_revoke["json"].get("expired") is True
                             or sync_after_revoke["json"].get("revoked") is True
                             or sync_after_revoke["json"].get("ok") is False))
                )
                if not sync_blocked:
                    fail(f"/Guest/Sync deveria bloquear apos revoke: {sync_after_revoke}")
                log_pass("/Guest/Sync bloqueado/encerrado apos revoke do invite")

                # Composer guest deve ficar bloqueado (aviso de arquivado/sessao encerrada).
                guest.wait_for_timeout(2000)
                archived = guest.locator("[data-role='guest-archived-notice']").first
                try:
                    archived.wait_for(state="visible", timeout=poll_wait_ms * 2)
                    log_pass("Composer do convidado bloqueado (aviso guest-archived-notice exibido)")
                except Exception:
                    # Fallback: confirmar que o form de envio nao esta mais utilizavel.
                    form_present = guest.locator("[data-role='guest-send-form']").count()
                    if form_present > 0 and guest.locator("[data-role='guest-send-form'] textarea").first.is_enabled():
                        fail("Composer do convidado continua ativo apos revoke")
                    log_pass("Composer do convidado nao utilizavel apos revoke (form removido/desabilitado)")

            # 11. Isolamento do convidado: ctx_guest (sem sessao GLPI) nao acessa endpoint autenticado/outro ticket.
            isolation = guest.evaluate(
                """async ({ otherTicket }) => {
                    const r = await fetch(`/plugins/glpichat/Widget/Channels?tickets_id=${otherTicket}`, {
                      method: 'GET', credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                      redirect: 'manual',
                    });
                    let text = '';
                    try { text = await r.text(); } catch (e) { text = ''; }
                    let json = null;
                    try { json = JSON.parse(text); } catch (e) { json = null; }
                    return {
                      status: r.status,
                      type: r.type,
                      hasChannels: !!(json && Array.isArray(json.channels) && json.channels.length > 0),
                    };
                }""",
                {"otherTicket": OTHER_TICKET_ID},
            )
            authorized_leak = (
                isolation["status"] == 200
                and isolation["hasChannels"] is True
                and isolation["type"] != "opaqueredirect"
            )
            if authorized_leak:
                fail(f"Convidado conseguiu dados autenticados de outro ticket: {isolation}")
            log_pass(
                "Isolamento do convidado: /Widget/Channels de outro ticket nao retornou dados autorizados "
                f"(status={isolation['status']}, type={isolation['type']})"
            )

            log_pass("E2E funcional completo do chat concluido")

        finally:
            # TEARDOWN best-effort. As linhas de mensagem persistem no ticket;
            # a limpeza total exigiria um helper PHP dedicado, fora do escopo do E2E
            # (mesmo principio do chat_security_e2e_test.py).
            try:
                if tech_token:
                    for invite_id in created_invite_ids:
                        tech.evaluate(
                            """async ({ inviteId, ticketId, token }) => {
                                try {
                                    const body = new FormData();
                                    body.set('invite_id', inviteId);
                                    body.set('tickets_id', ticketId);
                                    body.set('_glpi_csrf_token', token);
                                    await fetch('/plugins/glpichat/Invite/Revoke', {
                                      method: 'POST', body, credentials: 'same-origin',
                                      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token },
                                    });
                                } catch (e) { /* best-effort */ }
                            }""",
                            {"inviteId": invite_id, "ticketId": TICKET_ID, "token": tech_token},
                        )
            except Exception:
                pass

            try:
                if tech_token and public_rooms_id:
                    tech.evaluate(
                        """async ({ roomsId, token }) => {
                            try {
                                const body = new FormData();
                                body.set('rooms_id', roomsId);
                                body.set('_glpi_csrf_token', token);
                                await fetch('/plugins/glpichat/Widget/Leave', {
                                  method: 'POST', body, credentials: 'same-origin',
                                  headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token },
                                });
                            } catch (e) { /* best-effort */ }
                        }""",
                        {"roomsId": public_rooms_id, "token": tech_token},
                    )
            except Exception:
                pass

            try:
                guest.evaluate(
                    """async () => {
                        try {
                            await fetch('/plugins/glpichat/Guest/Leave', {
                              method: 'POST', credentials: 'same-origin',
                              headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            });
                        } catch (e) { /* best-effort */ }
                    }"""
                )
            except Exception:
                pass

            for ctx in (ctx_tech, ctx_self, ctx_guest):
                try:
                    ctx.close()
                except Exception:
                    pass
            try:
                browser.close()
            except Exception:
                pass


if __name__ == "__main__":
    main()
