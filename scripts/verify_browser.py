import os
import subprocess
import sqlite3
import time
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

base = Path(__file__).resolve().parent.parent
root = base / "qa-output"
root.mkdir(exist_ok=True)
if os.environ.get("DEBUG") != "1":
    raise RuntimeError("Verificación permitida solo con DEBUG=1 y la demo ficticia.")
password = os.environ["E2E_PASSWORD"]
op = (
    sqlite3.connect(base / "data/integra.sqlite3")
    .execute(
        "SELECT o.slug FROM core_opportunity o JOIN core_congregation c ON c.id=o.congregation_id WHERE c.slug='horizonte' AND c.name='Comunidad Horizonte · Demo' ORDER BY o.id LIMIT 1"
    )
    .fetchone()[0]
)
env = dict(os.environ, DEBUG="1")
server = subprocess.Popen(
    [
        str(base / ".venv/bin/python"),
        "manage.py",
        "runserver",
        "127.0.0.1:8001",
        "--noreload",
    ],
    cwd=base,
    env=env,
    stdout=subprocess.DEVNULL,
    stderr=subprocess.PIPE,
)
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(
            executable_path=os.environ.get("BROWSER_EXECUTABLE"),
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--single-process",
                "--no-zygote",
                "--disable-gpu",
                "--disable-software-rasterizer",
                "--use-gl=disabled",
            ],
        )
        page = browser.new_page(viewport={"width": 1440, "height": 1024})
        errors = []
        page.on("pageerror", lambda e: errors.append(str(e)))
        for _ in range(25):
            try:
                page.goto("http://127.0.0.1:8001/login/")
                break
            except Exception:
                time.sleep(0.2)
        page.locator("[name=username]").fill("admin@example.test")
        page.locator("[name=password]").fill(password)
        page.get_by_role("button", name="Iniciar sesión").click()
        page.wait_for_url("http://127.0.0.1:8001/")
        page.screenshot(path=str(root / "qa-dashboard-desktop.png"), full_page=True)
        page.get_by_role("button", name="Cambiar tema").click()
        page.screenshot(path=str(root / "qa-dashboard-dark.png"), full_page=True)
        page.set_viewport_size({"width": 360, "height": 800})
        page.screenshot(path=str(root / "qa-dashboard-mobile.png"), full_page=True)
        assert page.evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth"
        ), "Dashboard has horizontal overflow"
        page.goto("http://127.0.0.1:8001/catalog/workflows/new/")
        page.locator("[name=name]").fill("Flujo de revisión UI")
        assert page.locator("#builder-items .builder-item").count() == 2
        page.get_by_role("button", name="Guardar", exact=True).click()
        page.wait_for_url("**/catalog/workflows/")
        assert page.get_by_role("heading", name="Flujo de revisión UI").count() == 1
        page.set_viewport_size({"width": 1440, "height": 1024})
        page.goto("http://127.0.0.1:8001/catalog/questionnaires/new/")
        page.locator("[name=name]").fill("Cuestionario de revisión UI")
        page.get_by_role("button", name="Agregar pregunta").click()
        assert page.locator("#builder-items .builder-item").count() == 2
        page.screenshot(path=str(root / "qa-builder.png"), full_page=True)
        public = browser.new_context(viewport={"width": 360, "height": 800})
        candidate = public.new_page()
        candidate.goto("http://127.0.0.1:8001/apply/" + op + "/")
        candidate.locator("[name=name]").fill("Persona Verificación UI")
        candidate.locator("[name=email]").fill("ui-verification@example.test")
        candidate.locator("[name=phone]").fill("5551234567")
        candidate.locator("[name=q_motivation]").fill("Servir y aprender")
        candidate.locator("[name=q_skills]").fill("Organización y trabajo en equipo")
        candidate.locator("[name=q_availability]").select_option(
            "Domingo por la mañana"
        )
        candidate.locator("[name=consent]").check()
        assert candidate.evaluate(
            "document.documentElement.scrollWidth<=window.innerWidth"
        ), "Public form overflow"
        candidate.screenshot(path=str(root / "qa-public-mobile.png"), full_page=True)
        candidate.get_by_role("button", name="Enviar solicitud").click()
        candidate.wait_for_url("**/receipt/")
        assert candidate.get_by_role(
            "heading", name="Gracias por dar el primer paso."
        ).is_visible()
        page.goto("http://127.0.0.1:8001/applications/?q=Persona+Verificaci%C3%B3n+UI")
        page.locator(".application-row").first.click()
        assert page.get_by_role("heading", name="Persona Verificación UI").is_visible()
        note = page.locator("form").filter(
            has=page.locator("input[name=action][value=note]")
        )
        note.locator("[name=text]").fill("Seguimiento verificado en navegador.")
        note.get_by_role("button", name="Guardar nota").click()
        step = page.locator("form").filter(
            has=page.locator("input[name=action][value=transition]")
        )
        step.locator("[name=destination]").select_option("conversation")
        step.locator("[name=comment]").fill("Conversación programada")
        step.get_by_role("button", name="Registrar transición").click()
        assert (
            page.locator(".summary-strip")
            .get_by_text("Conversación", exact=True)
            .is_visible()
        )
        step = page.locator("form").filter(
            has=page.locator("input[name=action][value=transition]")
        )
        step.locator("[name=destination]").select_option("done")
        step.locator("[name=comment]").fill("Respuesta documentada del líder")
        step.locator("summary").click()
        step.locator("[name=leader_decision]").fill("Líder confirma integración")
        step.locator("[name=integration_role]").fill("Anfitrión")
        step.locator("[name=integration_date]").fill("2026-10-01")
        step.get_by_role("button", name="Registrar transición").click()
        assert (
            page.locator(".summary-strip")
            .get_by_text("Integrado", exact=True)
            .is_visible()
        )
        page.screenshot(path=str(root / "qa-application.png"), full_page=True)
        page.goto("http://127.0.0.1:8001/reports/")
        assert page.get_by_role("heading", name="Cumplimiento por fase").is_visible()
        assert not errors, errors
        print(
            json.dumps(
                {
                    "result": "PASS",
                    "checks": [
                        "desktop",
                        "dark",
                        "360px",
                        "no horizontal overflow",
                        "workflow builder save",
                        "questionnaire builder add",
                        "public submission and receipt",
                        "admin inbox persistence",
                        "activity and transitions",
                        "leader integration",
                        "reports",
                    ],
                    "page_errors": errors,
                }
            )
        )
        browser.close()
finally:
    server.terminate()
    server.wait(timeout=10)
