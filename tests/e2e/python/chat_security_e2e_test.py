import os
from pathlib import Path

from playwright.sync_api import sync_playwright


BASE_URL = os.getenv("GLPICHAT_BASE_URL", "http://localhost:8080")
TICKET_ID = os.getenv("GLPICHAT_TICKET_ID", "2")
FIXTURES_DIR = Path(__file__).resolve().parents[2] / "fixtures"


def fail(message: str) -> None:
    raise SystemExit(f"[FAIL] {message}")


def log_pass(message: str) -> None:
    print(f"[PASS] {message}")


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        headless=True,
        executable_path="/usr/bin/chromium",
    )
    page = browser.new_page()

    try:
        page.goto(f"{BASE_URL}/", wait_until="networkidle")
        page.fill("#login_name", "glpi")
        page.fill("#login_password", "glpi")
        page.click("button[type='submit']")
        page.wait_for_load_state("networkidle")

        if "/front/central.php" not in page.url:
            fail(f"Login no GLPI falhou: {page.url}")
        log_pass("Login real no GLPI via navegador")

        page.goto(f"{BASE_URL}/front/ticket.form.php?id={TICKET_ID}", wait_until="networkidle")

        channels_bootstrap = page.evaluate(
            """async ({ ticketId }) => {
                const response = await fetch(`/plugins/glpichat/Widget/Channels?tickets_id=${ticketId}`, {
                  method: "GET",
                  credentials: "same-origin",
                  headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                return { status: response.status, json: await response.json() };
            }""",
            {"ticketId": TICKET_ID},
        )
        if channels_bootstrap["status"] != 200 or not channels_bootstrap["json"].get("_glpi_csrf_token"):
            fail(f"Bootstrap do widget nao retornou token CSRF: {channels_bootstrap}")
        token = channels_bootstrap["json"]["_glpi_csrf_token"]
        log_pass("Token CSRF real extraido de /Widget/Channels")

        send_without_token = page.evaluate(
            """async ({ ticketId }) => {
                const body = new FormData();
                body.set("tickets_id", ticketId);
                body.set("room_type", "public");
                body.set("content", "playwright-sem-csrf");
                const response = await fetch("/plugins/glpichat/Send", {
                  method: "POST",
                  body,
                  credentials: "same-origin",
                  headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                return { status: response.status, text: await response.text() };
            }""",
            {"ticketId": TICKET_ID},
        )
        send_without_token_text = send_without_token["text"]
        send_without_token_blocked = (
            (send_without_token["status"] == 400 and "Invalid CSRF token." in send_without_token_text)
            or (send_without_token["status"] == 403 and "A ação que você requisitou não é permitida." in send_without_token_text)
        )
        if not send_without_token_blocked:
            fail(f"Envio sem CSRF nao retornou o erro esperado: {send_without_token}")
        log_pass("POST /Send sem CSRF eh bloqueado no navegador")

        chat_textarea = page.locator(".glpichat-widget .glpichat-send textarea").first
        chat_submit = page.locator(".glpichat-widget .glpichat-send .glpichat-send-btn").first
        chat_file_input = page.locator(".glpichat-widget .glpichat-send .glpichat-file-input").first

        chat_textarea.wait_for(state="visible", timeout=15000)
        log_pass("Widget do chat aberto no ticket")

        chat_textarea.fill("playwright-com-csrf")
        with page.expect_response(lambda response: "/plugins/glpichat/Send" in response.url and response.request.method == "POST") as send_response_info:
            chat_submit.click()
        send_response = send_response_info.value
        send_text = send_response.text()
        try:
            send_json = send_response.json()
        except Exception:
            send_json = None
        if send_response.status != 200 or not isinstance((send_json or {}).get("message_id"), int):
            fail(f"Envio com CSRF nao criou mensagem: status={send_response.status} body={send_text}")
        log_pass("POST /Send com CSRF cria mensagem pelo navegador")

        chat_file_input.set_input_files(str(FIXTURES_DIR / "allowed_upload.txt"))
        with page.expect_response(lambda response: "/plugins/glpichat/Upload" in response.url and response.request.method == "POST") as upload_ok_info:
            chat_submit.click()
        upload_ok_response = upload_ok_info.value
        upload_ok_json = upload_ok_response.json()
        if upload_ok_response.status != 200 or not isinstance(upload_ok_json.get("documents_id"), int):
            fail(f"Upload permitido nao funcionou: status={upload_ok_response.status} body={upload_ok_json}")
        log_pass("POST /Upload com arquivo permitido funciona pelo navegador")

        chat_file_input.set_input_files(str(FIXTURES_DIR / "blocked_upload.html"))
        with page.expect_response(lambda response: "/plugins/glpichat/Upload" in response.url and response.request.method == "POST") as upload_blocked_info:
            chat_submit.click()
        upload_blocked_response = upload_blocked_info.value
        upload_blocked_json = upload_blocked_response.json()
        if upload_blocked_response.status != 400 or "not allowed by GLPI" not in str(upload_blocked_json.get("error", "")):
            fail(
                "Upload bloqueado de .html nao falhou como esperado: "
                f"status={upload_blocked_response.status} body={upload_blocked_json}"
            )
        log_pass("POST /Upload com .html bloqueado falha pelo navegador")
    finally:
        page.close()
        browser.close()
