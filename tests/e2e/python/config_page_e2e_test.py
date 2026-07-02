"""E2E da tela de configuracao do plugin glpichat (Playwright + Chromium).

Motivacao: front/config.php montava o `action` do `<form>` com
$_SERVER['PHP_SELF'], que sob o roteamento Symfony do GLPI 11 resolvia para
"/index.php", fazendo QUALQUER salvamento nessa tela retornar 403. O fix
trocou para $_SERVER['REQUEST_URI'] (sem query string). A suite de testes ate
entao so exercitava Config::setConfigurationValues() direto em PHP, pulando o
pipeline de rota HTTP real - esta e a lacuna que este teste fecha.

Pre-requisitos do ambiente:
- GLPI servido em GLPICHAT_BASE_URL (default http://localhost:8080), com o
  plugin glpichat instalado e ativo.
- Usuario tecnico/admin: glpi/glpi (cai em /front/central.php) com direito
  plugin_glpichat_admin (READ+UPDATE).
- Chromium disponivel em /usr/bin/chromium.

Observacoes de estilo (espelha chat_full_e2e_test.py / chat_security_e2e_test.py):
- sync_playwright, Chromium em /usr/bin/chromium, BASE_URL via env.
- Helpers fail()/log_pass()/log_info().
- Mutacoes de config via fetch() no contexto da pagina com FormData +
  _glpi_csrf_token, igual ao padrao ja usado no teste de seguranca.
- TEARDOWN best-effort: restaura o snapshot original de TODOS os campos ao
  final (bloco finally), para nao deixar o ambiente de dev degradado -
  mesmo principio do tests/lifecycle/install_uninstall_test.php.
- Este teste NAO altera codigo de producao do plugin.
"""

import os

from playwright.sync_api import sync_playwright


BASE_URL = os.getenv("GLPICHAT_BASE_URL", "http://localhost:8080")
CONFIG_URL = f"{BASE_URL}/plugins/glpichat/front/config.php"
EXPECTED_ACTION_PATH = "/plugins/glpichat/front/config.php"

# Campos numericos (name, min, max) extraidos de front/config.php - fonte da
# verdade e o codigo, nao este comentario (ver bloco Config::setConfigurationValues).
NUMERIC_FIELDS = [
    # Bloco A - Polling & Sincronizacao
    ("poll_focused_ms", 1000, 30000),
    ("poll_idle_ms", 3000, 60000),
    ("poll_bg_ms", 5000, 120000),
    ("guest_poll_ms", 2000, 30000),
    ("meta_sync_ms", 5000, 120000),
    ("invite_poll_ms", 3000, 60000),
    ("poll_max_channels", 1, 50),
    ("user_inactivity_timeout_s", 30, 600),
    # Bloco B - Sessao & Seguranca
    ("guest_session_ttl", 60, 86400),
    ("invite_ttl_seconds", 300, 259200),
    ("guest_send_throttle_s", 1, 30),
    ("accept_rate_limit_window_s", 30, 300),
    ("accept_rate_limit_max_attempts", 3, 100),
    ("max_invites_per_ticket", 1, 50),
    # Bloco C - Interface & UX
    ("desktop_max_open", 1, 10),
    ("typing_indicator_timeout_ms", 1000, 10000),
    ("auto_scroll_threshold_px", 50, 500),
    ("unread_badge_threshold", 1, 10),
    # Bloco D - Comportamento do Chat
    ("history_page_default", 10, 100),
    ("history_page_max", 10, 200),
    # Bloco E - Limpeza & Retencao
    ("events_retention_days", 7, 365),
    ("messages_retention_days", 30, 1825),
    ("auditlogs_retention_days", 30, 730),
]

VALID_POSITIONS = ["bottom-right", "bottom-left", "top-left", "top-right"]
ENUM_FALLBACK = "bottom-right"

# Mapa aba -> id do botao "Salvar" dentro dela (todas compartilham o mesmo
# <form id="glpichat-config-form">, um botao de submit por painel de aba).
TABS = [
    ("tab-sync-link", "tab-sync"),
    ("tab-seguranca-link", "tab-seguranca"),
    ("tab-interface-link", "tab-interface"),
    ("tab-chat-link", "tab-chat"),
    ("tab-retencao-link", "tab-retencao"),
    ("tab-integracao-link", "tab-integracao"),
]

# Campos numericos por aba (name, min, max, so_pode_aumentar). Campos de
# retencao so podem AUMENTAR neste teste: reduzir qualquer campo de retencao
# dispara o modal JS glpichat-confirm-modal (ver script no final de
# front/config.php, compara com origRetention), que intercepta o submit real
# de clique de UI - fora do escopo deste teste.
TAB_NUMERIC_FIELDS = {
    "tab-sync": [
        ("poll_focused_ms", 1000, 30000, False),
        ("poll_idle_ms", 3000, 60000, False),
        ("poll_bg_ms", 5000, 120000, False),
        ("guest_poll_ms", 2000, 30000, False),
        ("meta_sync_ms", 5000, 120000, False),
        ("invite_poll_ms", 3000, 60000, False),
        ("poll_max_channels", 1, 50, False),
        ("user_inactivity_timeout_s", 30, 600, False),
    ],
    "tab-seguranca": [
        ("guest_session_ttl", 60, 86400, False),
        ("invite_ttl_seconds", 300, 259200, False),
        ("guest_send_throttle_s", 1, 30, False),
        ("accept_rate_limit_window_s", 30, 300, False),
        ("accept_rate_limit_max_attempts", 3, 100, False),
        ("max_invites_per_ticket", 1, 50, False),
    ],
    "tab-interface": [
        ("desktop_max_open", 1, 10, False),
        ("typing_indicator_timeout_ms", 1000, 10000, False),
        ("auto_scroll_threshold_px", 50, 500, False),
        ("unread_badge_threshold", 1, 10, False),
    ],
    "tab-chat": [
        ("history_page_default", 10, 100, False),
        ("history_page_max", 10, 200, False),
    ],
    "tab-retencao": [
        ("events_retention_days", 7, 365, True),
        ("messages_retention_days", 30, 1825, True),
        ("auditlogs_retention_days", 30, 730, True),
    ],
}

# Campos numericos por aba que tambem sao expostos por GET /Widget/Config
# (ver WidgetController::config()) - para estes, alem de reler o HTML,
# cross-checamos o valor retornado pelo endpoint real usado em runtime.
WIDGET_CONFIG_NUMERIC_FIELDS = {
    "poll_focused_ms",
    "poll_idle_ms",
    "poll_bg_ms",
    "meta_sync_ms",
    "invite_poll_ms",
    "desktop_max_open",
    "typing_indicator_timeout_ms",
    "auto_scroll_threshold_px",
    "history_page_default",
    "history_page_max",
}

# widget_default_position e auto_open_ticket_chat tambem sao expostos por
# GET /Widget/Config; external_followup_user_id NAO e.
WIDGET_CONFIG_NON_NUMERIC_FIELDS = {"widget_default_position", "auto_open_ticket_chat"}

# external_followup_user_id (aba Integracao): id de usuario existente/ativo,
# distinto do valor corrente (que costuma ser 9 = Marcos Souza neste ambiente).
INTEGRATION_ALT_USER_ID = "4"  # "tech", usuario padrao do GLPI, sempre presente

FORBIDDEN_TEXT_PT = "Você não tem permissão para executar essa ação."
FORBIDDEN_TEXT_EN = "You don't have permission to execute this action."


def fail(message: str) -> None:
    raise SystemExit(f"[FAIL] {message}")


def log_pass(message: str) -> None:
    print(f"[PASS] {message}")


def log_info(message: str) -> None:
    print(f"[INFO] {message}")


def login_technician(page) -> None:
    page.goto(f"{BASE_URL}/", wait_until="networkidle")
    page.fill("#login_name", "glpi")
    page.fill("#login_password", "glpi")
    page.click("button[type='submit']")
    page.wait_for_load_state("networkidle")
    if "/front/central.php" not in page.url:
        fail(f"Login do tecnico (glpi) falhou: {page.url}")
    log_pass("Login do tecnico (glpi) no GLPI")


def read_csrf_token(page) -> str:
    token = page.locator("input[name='_glpi_csrf_token']").first.get_attribute("value")
    if not token:
        fail("Token CSRF nao encontrado no form #glpichat-config-form")
    return token


def read_numeric_snapshot(page) -> dict:
    """Le o valor atual (do HTML renderizado) de cada campo numerico pelo id."""
    snapshot = {}
    for name, _min, _max in NUMERIC_FIELDS:
        value = page.locator(f"#{name}").first.get_attribute("value")
        if value is None:
            fail(f"Input numerico #{name} nao encontrado no form")
        snapshot[name] = value
    return snapshot


def read_non_numeric_snapshot(page) -> dict:
    position = page.locator("#widget_default_position").first.input_value()
    auto_open_checked = page.locator("#auto_open_ticket_chat").first.is_checked()
    followup_user_id = page.locator("select[name='external_followup_user_id']").first.input_value()
    return {
        "widget_default_position": position,
        "auto_open_ticket_chat": "1" if auto_open_checked else "0",
        "external_followup_user_id": followup_user_id,
    }


def compute_reduced_value(current: int, mn: int) -> int | None:
    """Calcula um novo valor MENOR que `current` (>= mn), para exercitar o
    caminho de reducao de retencao (dispara o modal JS de confirmacao).

    Retorna None se nao houver margem para reduzir (current <= mn).
    """
    if current <= mn:
        return None
    step = max(1, (current - mn) // 3)
    candidate = current - step
    if candidate < mn:
        candidate = mn
    if candidate >= current:
        candidate = mn
    return candidate


def compute_bumped_value(current: int, mn: int, mx: int, increase_only: bool) -> int:
    """Calcula um novo valor distinto de `current`, dentro de [mn, mx].

    Prefere incrementar em ~10% do range (minimo 1). Se o incremento
    estourar o maximo, cai para decremento - exceto quando `increase_only`
    (usado por events_retention_days, que so pode subir neste teste, para
    nao disparar o modal JS de confirmacao de reducao de retencao).
    """
    step = max(1, (mx - mn) // 10)
    up = current + step
    if up <= mx:
        return up
    if not increase_only:
        down = current - step
        if down >= mn:
            return down
    # Ultimo recurso: qualquer valor valido e distinto dentro dos limites.
    candidate = mx if current != mx else mn
    if increase_only and candidate < current:
        fail(
            f"Nao foi possivel calcular um valor de teste MAIOR que {current} "
            f"dentro do limite [{mn}, {mx}]"
        )
    return candidate


def set_native_select_value(page, select_locator, value: str) -> None:
    """Seleciona `value` no <select> nativo por tras de um dropdown select2 do
    GLPI (User::dropdown via ajax). O DOM so traz pre-renderizada a <option>
    do valor atualmente selecionado - as demais sao carregadas via ajax
    quando o usuario busca no widget select2. Para exercitar uma interacao
    real de Playwright sem depender da UI flutuante do select2 (fragil em
    modo headless), garantimos que a <option> alvo exista no <select> nativo
    e usamos `select_option`, que dispara o evento `change` nativo - o mesmo
    evento que o select2 escuta para sincronizar sua propria renderizacao, e
    que o form usa para saber o que submeter.
    """
    select_id = select_locator.get_attribute("id")
    select_locator.evaluate(
        """(el, val) => {
            if (!el.querySelector(`option[value="${val}"]`)) {
                const opt = document.createElement('option');
                opt.value = val;
                opt.text = val;
                el.appendChild(opt);
            }
        }""",
        value,
    )
    select_locator.select_option(value=value)
    log_info(f"select2 nativo #{select_id}: valor selecionado via UI = '{value}'")


def fetch_config(page) -> dict:
    """Le GET /Widget/Config (mesmo endpoint real usado pelo widget em
    runtime, ver WidgetController::config()) e retorna o JSON de config.
    Mesmo padrao de chat_full_e2e_test.py::fetch_config().
    """
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
    return result["json"]


def submit_config(page, token: str, numeric_values: dict, non_numeric: dict) -> dict:
    """Submete o form via fetch (mesma tecnica de chat_security_e2e_test.py)."""
    payload = dict(numeric_values)
    payload["widget_default_position"] = non_numeric["widget_default_position"]
    payload["external_followup_user_id"] = non_numeric["external_followup_user_id"]
    payload["_glpi_csrf_token"] = token
    payload["update"] = "1"
    # auto_open_ticket_chat eh um checkbox: so eh enviado se "marcado".
    include_auto_open = non_numeric["auto_open_ticket_chat"] == "1"

    result = page.evaluate(
        """async ({ action, fields, includeAutoOpen }) => {
            const body = new FormData();
            for (const [key, value] of Object.entries(fields)) {
                body.set(key, String(value));
            }
            if (includeAutoOpen) {
                body.set('auto_open_ticket_chat', '1');
            }
            const response = await fetch(action, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': fields._glpi_csrf_token,
                },
            });
            let text = '';
            try { text = await response.text(); } catch (e) { text = ''; }
            return { status: response.status, url: response.url, text };
        }""",
        {"action": EXPECTED_ACTION_PATH, "fields": payload, "includeAutoOpen": include_auto_open},
    )
    if result["status"] not in (200, 302):
        fail(f"POST de config falhou: status={result['status']} url={result['url']}")
    if FORBIDDEN_TEXT_PT in result["text"] or FORBIDDEN_TEXT_EN in result["text"]:
        fail(f"POST de config retornou 403 (regressao do bug de form action): {result}")
    return result


def main() -> None:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            headless=True,
            executable_path="/usr/bin/chromium",
        )
        context = browser.new_context()
        page = context.new_page()

        original_numeric: dict | None = None
        original_non_numeric: dict | None = None

        try:
            # Setup: login e primeira carga da tela de config.
            login_technician(page)
            page.goto(CONFIG_URL, wait_until="networkidle")
            form = page.locator("#glpichat-config-form").first
            form.wait_for(state="attached", timeout=15000)

            # 1. Regressao do bug do form action.
            action = form.get_attribute("action")
            if action != EXPECTED_ACTION_PATH:
                fail(
                    "form#glpichat-config-form action incorreto (regressao do bug "
                    f"PHP_SELF vs REQUEST_URI): esperado='{EXPECTED_ACTION_PATH}' obtido='{action}'"
                )
            log_pass(f"form#glpichat-config-form action correto: '{action}'")

            # 2a. Snapshot inicial (baseline + restauracao no teardown).
            original_numeric = read_numeric_snapshot(page)
            original_non_numeric = read_non_numeric_snapshot(page)
            log_pass(
                f"Snapshot capturado: {len(original_numeric)} campos numericos + "
                f"widget_default_position={original_non_numeric['widget_default_position']} "
                f"auto_open_ticket_chat={original_non_numeric['auto_open_ticket_chat']} "
                f"external_followup_user_id={original_non_numeric['external_followup_user_id']}"
            )

            # 2b. Submissao em lote com TODOS os campos abaixo do minimo.
            token = read_csrf_token(page)
            below_min = {name: max(mn - 1, -100) for name, mn, mx in NUMERIC_FIELDS}
            submit_config(page, token, below_min, original_non_numeric)
            page.goto(CONFIG_URL, wait_until="networkidle")

            clamp_min_errors = []
            for name, mn, mx in NUMERIC_FIELDS:
                actual = page.locator(f"#{name}").first.get_attribute("value")
                if str(actual) != str(mn):
                    clamp_min_errors.append(f"{name}: esperado min={mn}, obtido={actual}")
            if clamp_min_errors:
                fail("Clamp de minimo falhou para: " + "; ".join(clamp_min_errors))
            log_pass(f"Clamp de MINIMO validado para os {len(NUMERIC_FIELDS)} campos numericos")

            # 2c. Submissao em lote com TODOS os campos acima do maximo, e o enum
            # widget_default_position invalido na mesma submissao (item 3 do escopo).
            token = read_csrf_token(page)
            above_max = {name: mx + 1 for name, mn, mx in NUMERIC_FIELDS}
            invalid_enum_payload = dict(original_non_numeric)
            invalid_enum_payload["widget_default_position"] = "not-a-position"
            submit_config(page, token, above_max, invalid_enum_payload)
            page.goto(CONFIG_URL, wait_until="networkidle")

            clamp_max_errors = []
            for name, mn, mx in NUMERIC_FIELDS:
                actual = page.locator(f"#{name}").first.get_attribute("value")
                if str(actual) != str(mx):
                    clamp_max_errors.append(f"{name}: esperado max={mx}, obtido={actual}")
            if clamp_max_errors:
                fail("Clamp de maximo falhou para: " + "; ".join(clamp_max_errors))
            log_pass(f"Clamp de MAXIMO validado para os {len(NUMERIC_FIELDS)} campos numericos")

            # 3. Enum widget_default_position invalido cai no fallback correto.
            actual_position = page.locator("#widget_default_position").first.input_value()
            if actual_position != ENUM_FALLBACK:
                fail(
                    f"widget_default_position invalido nao caiu no fallback esperado "
                    f"'{ENUM_FALLBACK}': obtido='{actual_position}'"
                )
            log_pass(f"widget_default_position invalido caiu no fallback correto: '{actual_position}'")

            # Restaura o snapshot ANTES dos testes de UI por aba, para que cada
            # clique real de "Salvar" reenvie um form ja em estado valido/original.
            token = read_csrf_token(page)
            submit_config(page, token, original_numeric, original_non_numeric)
            page.goto(CONFIG_URL, wait_until="networkidle")
            log_pass("Snapshot restaurado antes dos testes de UI por aba")

            # 4. Cada botao "Salvar" (um por aba) dispara um submit valido do form
            # inteiro, sem 403, E realmente persiste a mudanca de TODOS os
            # campos daquela aba via interacao real de UI (fill/select_option/
            # check) - nao apenas ausencia de erro. Fluxo por aba: clicar na
            # aba, mudar TODOS os campos via UI, clicar no Salvar DAQUELA aba,
            # checar ausencia de 403, reler cada campo pos-navegacao (HTML) e,
            # para os campos tambem expostos por GET /Widget/Config, cross-
            # checar contra esse endpoint real usado em runtime.
            total_fields_validated = 0
            total_fields_cross_checked = 0

            for tab_link_id, tab_pane_id in TABS:
                page.locator(f"#{tab_link_id}").click()
                page.locator(f"#{tab_pane_id}").wait_for(state="visible", timeout=5000)

                is_integration_tab = tab_pane_id == "tab-integracao"
                is_interface_tab = tab_pane_id == "tab-interface"

                # expected_values: name -> valor esperado pos-persistencia,
                # ja no "formato HTML" (string para number/select, "1"/"0"
                # para checkbox).
                expected_values: dict[str, str] = {}
                old_values: dict[str, str] = {}

                if is_integration_tab:
                    select_locator = page.locator("select[name='external_followup_user_id']").first
                    old_value = select_locator.input_value()
                    new_value = INTEGRATION_ALT_USER_ID
                    if new_value == old_value:
                        fail(
                            "INTEGRATION_ALT_USER_ID coincide com o valor atual "
                            f"'{old_value}' - ajuste o id alternativo no teste"
                        )
                    set_native_select_value(page, select_locator, new_value)
                    old_values["external_followup_user_id"] = old_value
                    expected_values["external_followup_user_id"] = new_value
                else:
                    for field_name, mn, mx, increase_only in TAB_NUMERIC_FIELDS.get(tab_pane_id, []):
                        field_locator = page.locator(f"#{field_name}").first
                        old_value_str = field_locator.get_attribute("value")
                        if old_value_str is None:
                            fail(f"Input numerico #{field_name} nao encontrado na aba '{tab_pane_id}'")
                        old_value_int = int(old_value_str)
                        new_value_int = compute_bumped_value(old_value_int, mn, mx, increase_only)
                        if new_value_int == old_value_int or not (mn <= new_value_int <= mx):
                            fail(
                                f"Valor novo calculado para '{field_name}' invalido: "
                                f"antigo={old_value_int} novo={new_value_int} bounds=[{mn}, {mx}]"
                            )
                        field_locator.fill(str(new_value_int))
                        old_values[field_name] = old_value_str
                        expected_values[field_name] = str(new_value_int)

                    if is_interface_tab:
                        # widget_default_position: <select> nativo (nao select2)
                        # com as 4 opcoes validas - rotaciona para uma diferente
                        # da atual.
                        position_locator = page.locator("#widget_default_position").first
                        old_position = position_locator.input_value()
                        candidates = [p for p in VALID_POSITIONS if p != old_position]
                        if not candidates:
                            fail("Nenhuma posicao alternativa disponivel em VALID_POSITIONS")
                        new_position = candidates[0]
                        position_locator.select_option(value=new_position)
                        old_values["widget_default_position"] = old_position
                        expected_values["widget_default_position"] = new_position

                        # auto_open_ticket_chat: checkbox - inverte o booleano atual.
                        checkbox_locator = page.locator("#auto_open_ticket_chat").first
                        old_checked = checkbox_locator.is_checked()
                        new_checked = not old_checked
                        checkbox_locator.set_checked(new_checked)
                        old_values["auto_open_ticket_chat"] = "1" if old_checked else "0"
                        expected_values["auto_open_ticket_chat"] = "1" if new_checked else "0"

                save_btn = page.locator(f"#{tab_pane_id} button[type='submit'][name='update']").first
                save_btn.wait_for(state="visible", timeout=5000)
                with page.expect_navigation(wait_until="networkidle"):
                    save_btn.click()

                body_text = page.locator("body").inner_text() or ""
                if FORBIDDEN_TEXT_PT in body_text or FORBIDDEN_TEXT_EN in body_text:
                    fail(f"Salvar da aba '{tab_pane_id}' retornou tela de 403")
                if page.locator("#glpichat-config-form").count() < 1:
                    fail(f"Salvar da aba '{tab_pane_id}' nao retornou a tela de config (form ausente)")

                # 4a. Confirma persistencia real via HTML re-renderizado: reler
                # CADA campo mudado nesta aba pos-navegacao e comparar com o
                # valor exato que foi setado (nao com o antigo).
                html_errors = []
                persisted_html: dict[str, str] = {}
                for field_name, new_value in expected_values.items():
                    if field_name == "external_followup_user_id":
                        persisted = page.locator("select[name='external_followup_user_id']").first.input_value()
                    elif field_name == "widget_default_position":
                        persisted = page.locator("#widget_default_position").first.input_value()
                    elif field_name == "auto_open_ticket_chat":
                        persisted = "1" if page.locator("#auto_open_ticket_chat").first.is_checked() else "0"
                    else:
                        persisted = page.locator(f"#{field_name}").first.get_attribute("value")
                    persisted_html[field_name] = str(persisted)
                    if str(persisted) != str(new_value):
                        html_errors.append(
                            f"{field_name}: esperado='{new_value}' obtido='{persisted}' "
                            f"(antigo='{old_values.get(field_name)}')"
                        )
                if html_errors:
                    fail(
                        f"Salvar da aba '{tab_pane_id}' nao persistiu (HTML) para: "
                        + "; ".join(html_errors)
                    )
                total_fields_validated += len(expected_values)
                log_pass(
                    f"Aba '{tab_pane_id}': {len(expected_values)} campo(s) persistido(s) via UI real "
                    f"(HTML re-renderizado, sem 403): {expected_values}"
                )

                # 4b. Cross-check contra GET /Widget/Config: para os campos
                # expostos por esse endpoint (o que a aplicacao realmente le
                # em runtime), o valor retornado deve bater com o novo valor
                # setado - falha dura se divergir, nao apenas o HTML do form.
                cross_check_fields = {
                    name: new_value
                    for name, new_value in expected_values.items()
                    if name in WIDGET_CONFIG_NUMERIC_FIELDS or name in WIDGET_CONFIG_NON_NUMERIC_FIELDS
                }
                if cross_check_fields:
                    runtime_config = fetch_config(page)
                    runtime_errors = []
                    for field_name, new_value in cross_check_fields.items():
                        if field_name not in runtime_config:
                            runtime_errors.append(f"{field_name}: ausente em GET /Widget/Config")
                            continue
                        runtime_value = runtime_config[field_name]
                        if field_name == "auto_open_ticket_chat":
                            expected_bool = new_value == "1"
                            if bool(runtime_value) != expected_bool:
                                runtime_errors.append(
                                    f"{field_name}: esperado={expected_bool} obtido={runtime_value}"
                                )
                        elif field_name == "widget_default_position":
                            if str(runtime_value) != str(new_value):
                                runtime_errors.append(
                                    f"{field_name}: esperado='{new_value}' obtido='{runtime_value}'"
                                )
                        else:
                            if str(runtime_value) != str(new_value):
                                runtime_errors.append(
                                    f"{field_name}: esperado='{new_value}' obtido='{runtime_value}'"
                                )
                    if runtime_errors:
                        fail(
                            f"Cross-check GET /Widget/Config falhou para aba '{tab_pane_id}': "
                            + "; ".join(runtime_errors)
                        )
                    total_fields_cross_checked += len(cross_check_fields)
                    log_pass(
                        f"Aba '{tab_pane_id}': {len(cross_check_fields)} campo(s) cross-checado(s) "
                        f"com sucesso contra GET /Widget/Config"
                    )

            log_pass(
                f"Todos os botoes Salvar validados via clique real de UI: "
                f"{total_fields_validated} campos persistidos (HTML), "
                f"{total_fields_cross_checked} cross-checados via /Widget/Config"
            )

            # 5. Reducao de retencao: exercita de verdade o modal JS
            # glpichat-confirm-modal (ver <script> no fim de front/config.php).
            # Ate aqui os campos de retencao so foram AUMENTADOS (item 4), logo
            # o servidor esta com valores > original. Recarregamos a pagina
            # para que `origRetention` (injetado pelo PHP a partir do estado
            # persistido) reflita esses valores aumentados, e entao REDUZIMOS
            # events_retention_days (e, se houver margem, messages/auditlogs
            # tambem) - isso faz `newVal < origVal` no JS, disparando
            # e.preventDefault() e o modal de confirmacao.
            page.goto(CONFIG_URL, wait_until="networkidle")
            page.locator("#tab-retencao-link").click()
            page.locator("#tab-retencao").wait_for(state="visible", timeout=5000)

            retention_reduction: dict[str, tuple[int, int]] = {}  # name -> (old, new)
            for field_name, mn, mx, _increase_only in TAB_NUMERIC_FIELDS["tab-retencao"]:
                field_locator = page.locator(f"#{field_name}").first
                current_str = field_locator.get_attribute("value")
                if current_str is None:
                    fail(f"Input numerico #{field_name} nao encontrado na aba 'tab-retencao'")
                current_int = int(current_str)
                reduced_int = compute_reduced_value(current_int, mn)
                if reduced_int is None:
                    log_info(
                        f"Aba 'tab-retencao': '{field_name}' sem margem para reduzir "
                        f"(atual={current_int}, min={mn}) - pulando este campo no teste do modal"
                    )
                    continue
                field_locator.fill(str(reduced_int))
                retention_reduction[field_name] = (current_int, reduced_int)

            if "events_retention_days" not in retention_reduction:
                fail(
                    "Nao foi possivel calcular uma reducao valida para "
                    "'events_retention_days' - impossivel exercitar o modal de confirmacao"
                )

            # 5a. Clicar em Salvar NAO deve navegar imediatamente: o listener
            # de 'submit' do form intercepta (e.preventDefault()) porque ha
            # reducao de retencao, e exibe o modal de confirmacao.
            save_btn = page.locator("#tab-retencao button[type='submit'][name='update']").first
            save_btn.wait_for(state="visible", timeout=5000)
            save_btn.click()

            modal_locator = page.locator("#glpichat-confirm-modal")
            modal_locator.wait_for(state="visible", timeout=5000)
            log_pass("Modal 'glpichat-confirm-modal' de reducao de retencao ficou visivel apos Salvar")

            changes_text = page.locator("#glpichat-confirm-changes-list").inner_text()
            if "Retenção de eventos" not in changes_text:
                fail(
                    "Lista de mudancas do modal nao menciona 'Retenção de eventos': "
                    f"{changes_text!r}"
                )
            old_events, new_events = retention_reduction["events_retention_days"]
            if str(old_events) not in changes_text or str(new_events) not in changes_text:
                fail(
                    f"Lista de mudancas do modal nao contem os valores antigo/novo "
                    f"esperados ({old_events} -> {new_events}): {changes_text!r}"
                )
            log_pass(
                f"Modal de confirmacao lista corretamente a reducao de "
                f"'events_retention_days' ({old_events} -> {new_events})"
            )

            # 5b (bonus). Cancelar fecha o modal SEM submeter o form: o valor
            # reduzido continua so localmente no input, mas nao foi
            # persistido no servidor.
            cancel_btn = page.locator(
                "#glpichat-confirm-modal button[data-bs-dismiss='modal']:has-text('Cancelar')"
            ).first
            cancel_btn.click()
            modal_locator.wait_for(state="hidden", timeout=5000)
            log_pass("Modal fechado via 'Cancelar' sem submeter o form")

            page.goto(CONFIG_URL, wait_until="networkidle")
            page.locator("#tab-retencao-link").click()
            page.locator("#tab-retencao").wait_for(state="visible", timeout=5000)
            after_cancel_value = page.locator("#events_retention_days").first.get_attribute("value")
            if str(after_cancel_value) != str(old_events):
                fail(
                    "Cancelar o modal NAO deveria ter persistido a reducao, mas "
                    f"'events_retention_days' no servidor mudou: esperado='{old_events}' "
                    f"obtido='{after_cancel_value}'"
                )
            log_pass(
                f"Confirmado: Cancelar o modal nao persistiu a reducao no servidor "
                f"('events_retention_days' continua em {after_cancel_value})"
            )

            # 5c. Repete a reducao e desta vez CONFIRMA via
            # #glpichat-confirm-save-btn - isso deve chamar form.requestSubmit()
            # de verdade e navegar, persistindo o valor reduzido.
            for field_name, (old_value, new_value) in retention_reduction.items():
                page.locator(f"#{field_name}").first.fill(str(new_value))

            save_btn = page.locator("#tab-retencao button[type='submit'][name='update']").first
            save_btn.click()
            modal_locator.wait_for(state="visible", timeout=5000)

            confirm_btn = page.locator("#glpichat-confirm-save-btn")
            with page.expect_navigation(wait_until="networkidle"):
                confirm_btn.click()

            body_text = page.locator("body").inner_text() or ""
            if FORBIDDEN_TEXT_PT in body_text or FORBIDDEN_TEXT_EN in body_text:
                fail("Confirmar reducao de retencao via modal retornou tela de 403")
            if page.locator("#glpichat-config-form").count() < 1:
                fail("Confirmar reducao de retencao via modal nao retornou a tela de config")

            reduction_confirm_errors = []
            for field_name, (old_value, new_value) in retention_reduction.items():
                persisted = page.locator(f"#{field_name}").first.get_attribute("value")
                if str(persisted) != str(new_value):
                    reduction_confirm_errors.append(
                        f"{field_name}: esperado='{new_value}' obtido='{persisted}' (antigo='{old_value}')"
                    )
            if reduction_confirm_errors:
                fail(
                    "Reducao de retencao confirmada via modal nao persistiu corretamente: "
                    + "; ".join(reduction_confirm_errors)
                )

            reduction_summary = ", ".join(
                f"{name} {old}->{new}" for name, (old, new) in retention_reduction.items()
            )
            log_pass(
                "Modal de confirmacao de reducao de retencao exibido, dados corretos "
                f"listados, 'Cancelar' verificado (nao persiste) e 'Confirmar e Salvar' "
                f"acionado com sucesso via UI real - persistiu: {reduction_summary}"
            )

            log_pass("E2E da tela de configuracao concluido")

        finally:
            # 5. Teardown: restaura TODOS os campos ao snapshot original.
            try:
                if original_numeric is not None and original_non_numeric is not None:
                    page.goto(CONFIG_URL, wait_until="networkidle")
                    token = read_csrf_token(page)
                    submit_config(page, token, original_numeric, original_non_numeric)
                    log_info("Teardown: snapshot original restaurado (best-effort)")
            except Exception as exc:
                log_info(f"Teardown: falha ao restaurar snapshot (best-effort, ok): {exc}")

            try:
                context.close()
            except Exception:
                pass
            try:
                browser.close()
            except Exception:
                pass


if __name__ == "__main__":
    main()
