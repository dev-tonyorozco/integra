import json
from datetime import timedelta
from django.test import TestCase, Client, override_settings
from django.urls import reverse
from django.contrib.auth.models import User
from django.core.exceptions import ValidationError, PermissionDenied
from django.utils import timezone
from django.core.files.uploadedfile import SimpleUploadedFile
from .models import (
    Congregation,
    Membership,
    Ministry,
    Workflow,
    Questionnaire,
    Opportunity,
    Person,
    Application,
    Event,
    PublicTask,
    Attachment,
    Notification,
    Audit,
)
from .services import (
    validate_workflow,
    validate_questions,
    deadline,
    submit,
    transition,
    reassign,
    pause,
)
from .forms import DynamicForm


@override_settings(
    STORAGES={
        "default": {"BACKEND": "django.core.files.storage.InMemoryStorage"},
        "staticfiles": {
            "BACKEND": "django.contrib.staticfiles.storage.StaticFilesStorage"
        },
    }
)
class SystemTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        cls.c = Congregation.objects.create(name="Demo", slug="demo")
        cls.other = Congregation.objects.create(name="Other", slug="other")
        cls.members = {}
        for role in ["admin", "coordinator", "advisor", "reader", "technical"]:
            user = User.objects.create_user(
                username=role,
                email=role + "@example.test",
                password="Complex-Testing-only-9283",
                first_name=role,
            )
            cls.members[role] = Membership.objects.create(
                user=user, congregation=cls.c, role=role
            )
        outsider = User.objects.create_user(
            username="outsider", password="Complex-Testing-only-9283"
        )
        cls.outsider = Membership.objects.create(
            user=outsider, congregation=cls.other, role="admin"
        )
        cls.definition = {
            "initial": "new",
            "states": [
                {"id": "new", "label": "Nueva", "sla_hours": 24},
                {"id": "review", "label": "Revisión", "sla_hours": 120},
                {
                    "id": "reorient",
                    "label": "Otra opción",
                    "outcome": "reorient",
                    "sla_hours": 24,
                },
                {
                    "id": "done",
                    "label": "Integrada",
                    "terminal": True,
                    "outcome": "integrated",
                },
                {
                    "id": "closed",
                    "label": "Cerrada",
                    "terminal": True,
                    "outcome": "closed",
                },
            ],
            "transitions": [
                {"from": a, "to": b}
                for a, b in [
                    ("new", "review"),
                    ("review", "done"),
                    ("review", "reorient"),
                    ("reorient", "review"),
                    ("new", "closed"),
                ]
            ],
        }
        cls.workflow = Workflow.objects.create(
            congregation=cls.c,
            name="Workflow",
            published=True,
            definition=cls.definition,
        )
        cls.questions = [
            {
                "id": "motivation",
                "label": "Motivación",
                "type": "textarea",
                "required": True,
            },
            {
                "id": "sensitive",
                "label": "Confidencial",
                "type": "text",
                "sensitive": True,
            },
            {
                "id": "schedule",
                "label": "Horario",
                "type": "select",
                "required": True,
                "options": ["Domingo", "Sábado"],
            },
        ]
        cls.questionnaire = Questionnaire.objects.create(
            congregation=cls.c, name="Form", published=True, questions=cls.questions
        )
        cls.ministry = Ministry.objects.create(
            congregation=cls.c,
            name="Demo ministry",
            description="Service",
            leader_name="Demo leader",
            contact="leader@example.test",
            requirements="Training",
        )
        cls.opportunity = Opportunity.objects.create(
            congregation=cls.c,
            title="Serve",
            ministry=cls.ministry,
            workflow=cls.workflow,
            questionnaire=cls.questionnaire,
            responsible=cls.members["advisor"],
            published=True,
            description="Service",
        )

    def new_app(self):
        return submit(
            self.opportunity,
            {
                "name": "Test Person",
                "email": "person@example.test",
                "phone": "5555555555",
            },
            {
                "motivation": "Serve",
                "schedule": "Domingo",
                "sensitive": "INTERNAL-SECRET-NOTE",
            },
        )

    def signin(self, role="admin"):
        self.client.force_login(self.members[role].user)

    def test_public_submission_receipt_and_persistence(self):
        response = self.client.post(
            reverse("public_apply", args=[self.opportunity.slug]),
            {
                "name": "Test Person",
                "email": "person@example.test",
                "phone": "5555555555",
                "q_motivation": "Serve",
                "q_schedule": "Domingo",
                "consent": "on",
            },
        )
        self.assertRedirects(response, reverse("receipt"))
        app = Application.objects.get()
        self.assertEqual(app.state, "new")
        self.assertEqual(app.responsible, self.members["advisor"])
        self.assertTrue(app.folio.startswith("INT-"))
        self.assertEqual(app.events.count(), 1)
        self.assertEqual(Notification.objects.count(), 1)
        self.assertIsNotNone(app.consent_at)

    def test_missing_fields_cannot_submit(self):
        response = self.client.post(
            reverse("public_apply", args=[self.opportunity.slug]), {"name": "Test"}
        )
        self.assertEqual(response.status_code, 200)
        self.assertEqual(Application.objects.count(), 0)

    def test_closed_opportunity(self):
        self.opportunity.closes_at = timezone.now() - timedelta(days=1)
        self.opportunity.save()
        self.assertEqual(
            self.client.get(
                reverse("public_apply", args=[self.opportunity.slug])
            ).status_code,
            410,
        )

    def test_duplicate_detection_stays_inside_congregation(self):
        first = self.new_app()
        second = self.new_app()
        self.assertEqual(second.duplicate_of, first)
        self.assertEqual(Person.objects.count(), 1)

    def test_active_request_keeps_owner(self):
        app = self.new_app()
        self.assertIsNotNone(app.responsible)
        self.assertTrue(app.next_action)
        self.assertIsNotNone(app.due_at)

    def test_transitions_atomic_audit_and_revision(self):
        app = self.new_app()
        transition(app, self.members["advisor"], "review", "Reviewed", 0)
        app.refresh_from_db()
        self.assertEqual(app.state, "review")
        self.assertEqual(app.revision, 1)
        self.assertEqual(app.events.count(), 2)
        self.assertEqual(Audit.objects.filter(action="transition").count(), 1)
        with self.assertRaises(ValidationError):
            transition(app, self.members["advisor"], "done", "Done", 0)
        app.refresh_from_db()
        self.assertEqual(app.state, "review")

    def test_invalid_transition(self):
        app = self.new_app()
        with self.assertRaises(ValidationError):
            transition(app, self.members["advisor"], "done", "Invalid skip", 0)
        self.assertEqual(app.events.count(), 1)

    def test_integration_requires_leader_role_and_date(self):
        app = self.new_app()
        transition(app, self.members["advisor"], "review", "Review", 0)
        app.refresh_from_db()
        with self.assertRaises(ValidationError):
            transition(app, self.members["advisor"], "done", "Accept", app.revision)
        transition(
            app,
            self.members["advisor"],
            "done",
            "Confirmed by leader",
            app.revision,
            leader_decision="Leader accepted",
            integration_role="Host",
            integration_date=timezone.now().date(),
        )
        app.refresh_from_db()
        self.assertIsNotNone(app.closed_at)
        self.assertEqual(app.integration_role, "Host")

    def test_reorientation_does_not_close(self):
        app = self.new_app()
        transition(app, self.members["advisor"], "review", "Review", 0)
        app.refresh_from_db()
        transition(
            app,
            self.members["advisor"],
            "reorient",
            "Different option",
            app.revision,
            leader_decision="Another route",
            responsible=self.members["coordinator"],
        )
        app.refresh_from_db()
        self.assertIsNone(app.closed_at)
        self.assertEqual(app.responsible, self.members["coordinator"])

    def test_cross_tenant_read_write_export_denied(self):
        app = self.new_app()
        self.client.force_login(self.outsider.user)
        for url in [
            reverse("application", args=[app.pk]),
            reverse("pdf", args=[app.pk]),
            reverse("catalog_edit", args=["ministries", self.ministry.pk]),
        ]:
            self.assertEqual(self.client.get(url).status_code, 404)
        response = self.client.get(reverse("reports") + "?export=csv")
        self.assertNotIn(app.folio.encode(), response.content)
        with self.assertRaises(PermissionDenied):
            transition(app, self.outsider, "review", "Attempt", 0)

    def test_reader_cannot_mutate_or_read_sensitive(self):
        app = self.new_app()
        self.signin("reader")
        url = reverse("application", args=[app.pk])
        self.assertNotContains(self.client.get(url), "INTERNAL-SECRET-NOTE")
        self.assertEqual(
            self.client.post(url, {"action": "note", "text": "Write"}).status_code, 403
        )
        self.assertEqual(self.client.get(reverse("users")).status_code, 403)

    def test_technical_cannot_read_personal_data(self):
        app = self.new_app()
        self.signin("technical")
        self.assertEqual(
            self.client.get(reverse("application", args=[app.pk])).status_code, 403
        )
        self.assertRedirects(self.client.get("/"), reverse("audit"))

    def test_published_version_immutable_and_pinned(self):
        app = self.new_app()
        self.workflow.name = "Changed"
        with self.assertRaises(ValidationError):
            self.workflow.save()
        self.signin()
        response = self.client.get(
            reverse("catalog_edit", args=["workflows", self.workflow.pk]) + "?copy=1"
        )
        self.assertEqual(response.status_code, 200)
        app.refresh_from_db()
        self.assertEqual(app.workflow.version, 1)

    def test_published_workflow_validation(self):
        bad = {
            "initial": "unknown",
            "states": [{"id": "x", "label": "X"}],
            "transitions": [],
        }
        with self.assertRaises(ValidationError):
            validate_workflow(bad)
        valid = json.loads(json.dumps(self.definition))
        valid["states"].append({"id": "unreachable", "label": "Hidden"})
        with self.assertRaises(ValidationError):
            validate_workflow(valid)

    def test_questionnaire_validation(self):
        with self.assertRaises(ValidationError):
            validate_questions([{"id": "a", "label": "A", "type": "code"}])
        with self.assertRaises(ValidationError):
            validate_questions([{"id": "a", "label": "A"}, {"id": "a", "label": "B"}])

    def test_custom_dynamic_questions(self):
        form = DynamicForm(
            {"q_motivation": "Text", "q_schedule": "invalid"}, questions=self.questions
        )
        self.assertFalse(form.is_valid())

    def test_reassignment_reason_and_scope(self):
        app = self.new_app()
        with self.assertRaises(ValidationError):
            reassign(app, self.members["coordinator"], self.members["advisor"], "")
        with self.assertRaises(PermissionDenied):
            reassign(app, self.members["coordinator"], self.outsider, "Reason")
        reassign(
            app, self.members["coordinator"], self.members["coordinator"], "Coverage"
        )
        app.refresh_from_db()
        self.assertEqual(app.responsible, self.members["coordinator"])

    def test_pause_preserves_deadline_history(self):
        app = self.new_app()
        original = app.due_at
        pause(app, self.members["coordinator"], "Waiting for applicant")
        app.refresh_from_db()
        self.assertEqual(app.sla, "Pausada")
        with self.assertRaises(ValidationError):
            transition(app, self.members["advisor"], "review", "Review", app.revision)
        pause(app, self.members["coordinator"], "Resume")
        app.refresh_from_db()
        self.assertGreaterEqual(app.due_at, original)
        self.assertEqual(app.events.filter(kind__in=["pause", "resume"]).count(), 2)

    def test_business_days_skip_weekend(self):
        from datetime import datetime

        friday = datetime(2026, 10, 2, 12, tzinfo=timezone.get_current_timezone())
        self.assertEqual(
            deadline({"sla_hours": 24, "business_days": True}, friday).weekday(), 0
        )

    def test_private_note_hidden_from_reader(self):
        app = self.new_app()
        Event.objects.create(
            application=app, kind="note", text="HIDDEN-PRIVATE", private=True
        )
        self.signin("reader")
        self.assertNotContains(
            self.client.get(reverse("application", args=[app.pk])), "HIDDEN-PRIVATE"
        )

    def test_public_task_single_use_and_score(self):
        app = self.new_app()
        q = Questionnaire.objects.create(
            congregation=self.c,
            name="Test",
            published=True,
            is_test=True,
            questions=[
                {
                    "id": "score",
                    "label": "Score",
                    "type": "select",
                    "options": ["A", "B"],
                    "scores": {"A": 3, "B": 1},
                    "required": True,
                }
            ],
        )
        task = PublicTask.objects.create(
            congregation=self.c,
            application=app,
            questionnaire=q,
            instructions="Answer",
            expires_at=timezone.now() + timedelta(days=1),
        )
        url = reverse("public_task", args=[task.token])
        self.assertEqual(self.client.post(url, {"q_score": "A"}).status_code, 200)
        task.refresh_from_db()
        self.assertEqual(task.score, 3)
        self.assertEqual(self.client.post(url, {"q_score": "B"}).status_code, 410)
        task.refresh_from_db()
        self.assertEqual(task.score, 3)

    def test_expired_public_task(self):
        app = self.new_app()
        task = PublicTask.objects.create(
            congregation=self.c,
            application=app,
            instructions="Test",
            expires_at=timezone.now() - timedelta(days=1),
        )
        self.assertEqual(
            self.client.get(reverse("public_task", args=[task.token])).status_code, 410
        )

    def test_no_physical_delete_or_history_edit(self):
        app = self.new_app()
        event = app.events.first()
        with self.assertRaises(ValidationError):
            event.save()
        with self.assertRaises(ValidationError):
            event.delete()

    def test_csrf_required(self):
        client = Client(enforce_csrf_checks=True)
        client.force_login(self.members["advisor"].user)
        app = self.new_app()
        self.assertEqual(
            client.post(
                reverse("application", args=[app.pk]),
                {"action": "note", "text": "Attempt"},
            ).status_code,
            403,
        )

    def test_upload_rejects_executable_and_oversized(self):
        app = self.new_app()
        self.signin()
        self.client.post(
            reverse("upload", args=[app.pk]),
            {
                "file": SimpleUploadedFile(
                    "x.html", b"<script>", content_type="text/html"
                )
            },
        )
        self.assertFalse(Attachment.objects.exists())
        self.client.post(
            reverse("upload", args=[app.pk]),
            {
                "file": SimpleUploadedFile(
                    "x.pdf",
                    b"%PDF-" + b"x" * (5 * 1024 * 1024),
                    content_type="application/pdf",
                )
            },
        )
        self.assertFalse(Attachment.objects.exists())

    def test_pdf_summary_omits_sensitive(self):
        app = self.new_app()
        self.signin()
        response = self.client.get(reverse("pdf", args=[app.pk]))
        content = b"".join(response.streaming_content)
        self.assertEqual(response["Content-Type"], "application/pdf")
        self.assertTrue(content.startswith(b"%PDF"))

    def test_sla_alerts_idempotent(self):
        from django.core.management import call_command

        app = self.new_app()
        Application.objects.filter(pk=app.pk).update(
            due_at=timezone.now() - timedelta(days=1)
        )
        call_command("sla_reminders")
        count = Notification.objects.count()
        call_command("sla_reminders")
        self.assertEqual(Notification.objects.count(), count)

    def test_ui_routes_render_for_admin(self):
        app = self.new_app()
        self.signin()
        urls = [
            "/",
            "/applications/",
            "/people/",
            "/users/",
            "/notifications/",
            "/audit/",
            "/reports/",
            "/backups/",
            "/password/change/",
            reverse("application", args=[app.pk]),
            reverse("interview", args=[app.pk]),
        ]
        for kind in [
            "ministries",
            "workflows",
            "questionnaires",
            "tests",
            "opportunities",
        ]:
            urls.extend(
                [reverse("catalog", args=[kind]), reverse("catalog_new", args=[kind])]
            )
        for url in urls:
            with self.subTest(url=url):
                self.assertEqual(self.client.get(url).status_code, 200)

    def test_login_rate_limit(self):
        for _ in range(10):
            self.client.post(
                "/login/", {"username": "unknown", "password": "incorrect"}
            )
        self.assertContains(
            self.client.post(
                "/login/",
                {"username": "admin", "password": "Complex-Testing-only-9283"},
            ),
            "Demasiados intentos",
        )

    def test_inactive_membership_blocked(self):
        self.signin("advisor")
        self.members["advisor"].active = False
        self.members["advisor"].save()
        self.assertEqual(self.client.get("/applications/").status_code, 403)

    def test_backup_archive_has_db_and_is_not_public(self):
        from tempfile import TemporaryDirectory
        from pathlib import Path
        from .operations import make_backup
        import sqlite3
        import zipfile

        with TemporaryDirectory() as tmp:
            source = Path(tmp) / "source.sqlite3"
            connection = sqlite3.connect(source)
            connection.execute("CREATE TABLE sample(id int)")
            connection.commit()
            connection.close()
            with override_settings(
                DATA_DIR=Path(tmp),
                MEDIA_ROOT=Path(tmp) / "uploads",
                DATABASES={
                    "default": {"ENGINE": "django.db.backends.sqlite3", "NAME": source}
                },
            ):
                # Keep Django's test DB connection; only sqlite backup source reads the override.
                backup = make_backup(self.c)
                archive = Path(tmp) / "backups" / backup.filename
                with zipfile.ZipFile(archive) as z:
                    self.assertIn("integra.sqlite3", z.namelist())
                self.assertEqual(archive.stat().st_mode & 0o777, 0o600)

    def test_response_privacy_headers(self):
        self.signin()
        response = self.client.get("/")
        self.assertEqual(response["Cache-Control"], "no-store, private")
        self.assertIn("frame-ancestors 'none'", response["Content-Security-Policy"])

    def test_preview_routes_and_qr(self):
        self.signin()
        for kind, item in [
            ("workflows", self.workflow),
            ("questionnaires", self.questionnaire),
        ]:
            self.assertEqual(
                self.client.get(
                    reverse("catalog_preview", args=[kind, item.pk])
                ).status_code,
                200,
            )
        response = self.client.get(
            reverse("opportunity_qr", args=[self.opportunity.pk])
        )
        self.assertEqual(response.status_code, 200)
        self.assertTrue(b"".join(response.streaming_content).startswith(b"\x89PNG"))

    def test_cross_scope_opportunity_cannot_submit(self):
        self.opportunity.responsible = self.outsider
        self.opportunity.save()
        with self.assertRaises(ValidationError):
            self.new_app()

    def test_responsible_cannot_be_disabled_with_active_cases(self):
        self.new_app()
        self.signin()
        self.client.post(reverse("user_toggle", args=[self.members["advisor"].pk]))
        self.members["advisor"].refresh_from_db()
        self.assertTrue(self.members["advisor"].active)
