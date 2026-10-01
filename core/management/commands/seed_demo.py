import secrets
from datetime import timedelta
from django.core.management.base import BaseCommand, CommandError
from django.contrib.auth.models import User
from django.db import transaction
from django.utils import timezone
from core.models import (
    Congregation,
    Membership,
    Ministry,
    Workflow,
    Questionnaire,
    Opportunity,
    Application,
)
from core.services import submit, transition


class Command(BaseCommand):
    help = "Crea datos ficticios únicamente en instalaciones de desarrollo."

    @transaction.atomic
    def handle(self, *args, **options):
        from django.conf import settings

        if not settings.DEBUG:
            raise CommandError("La demo requiere DEBUG=1.")
        if Congregation.objects.exists():
            raise CommandError("La demo solo se crea en una base vacía.")
        password = secrets.token_urlsafe(18)
        c = Congregation.objects.create(
            name="Comunidad Horizonte · Demo", slug="horizonte"
        )
        c2 = Congregation.objects.create(
            name="Comunidad del Valle · Demo", slug="valle"
        )
        members = {}
        for role, name in [
            ("admin", "Andrea Demo"),
            ("coordinator", "Daniel Demo"),
            ("advisor", "Lucía Demo"),
            ("reader", "Consulta Demo"),
            ("technical", "Operación Demo"),
        ]:
            user = User.objects.create_user(
                username=role + "@example.test",
                email=role + "@example.test",
                password=password,
                first_name=name,
            )
            members[role] = Membership.objects.create(
                user=user, congregation=c, role=role
            )
        Membership.objects.create(
            user=members["admin"].user, congregation=c2, role="admin"
        )
        standard = {
            "initial": "received",
            "states": [
                {
                    "id": "received",
                    "label": "Recibido",
                    "sla_hours": 24,
                    "next_action": "Revisar perfil",
                },
                {
                    "id": "orientation",
                    "label": "Orientación",
                    "sla_hours": 24,
                    "next_action": "Orientar y preparar ficha",
                },
                {
                    "id": "leader",
                    "label": "Revisión con líder",
                    "sla_hours": 240,
                    "business_days": True,
                    "next_action": "Contactar y documentar decisión del líder",
                },
                {
                    "id": "training",
                    "label": "Capacitación",
                    "sla_hours": 360,
                    "business_days": True,
                    "next_action": "Completar formación",
                },
                {
                    "id": "integrated",
                    "label": "Integrado",
                    "sla_hours": 24,
                    "terminal": True,
                    "outcome": "integrated",
                    "next_action": "Integración confirmada",
                },
                {
                    "id": "reorient",
                    "label": "Reorientación",
                    "sla_hours": 24,
                    "outcome": "reorient",
                    "next_action": "Buscar otra oportunidad",
                },
                {
                    "id": "closed",
                    "label": "Cerrado",
                    "sla_hours": 24,
                    "terminal": True,
                    "outcome": "closed",
                    "next_action": "Caso finalizado",
                },
            ],
            "transitions": [
                {"from": a, "to": b, "roles": ["admin", "coordinator", "advisor"]}
                for a, b in [
                    ("received", "orientation"),
                    ("orientation", "leader"),
                    ("leader", "training"),
                    ("leader", "integrated"),
                    ("leader", "reorient"),
                    ("training", "integrated"),
                    ("training", "reorient"),
                    ("reorient", "orientation"),
                    ("received", "closed"),
                    ("orientation", "closed"),
                    ("leader", "closed"),
                ]
            ],
        }
        short = {
            "initial": "new",
            "states": [
                {
                    "id": "new",
                    "label": "Solicitud nueva",
                    "sla_hours": 24,
                    "next_action": "Agendar conversación",
                },
                {
                    "id": "conversation",
                    "label": "Conversación",
                    "sla_hours": 120,
                    "next_action": "Registrar respuesta del líder",
                },
                {
                    "id": "done",
                    "label": "Integrado",
                    "sla_hours": 24,
                    "terminal": True,
                    "outcome": "integrated",
                },
            ],
            "transitions": [
                {"from": "new", "to": "conversation"},
                {"from": "conversation", "to": "done"},
            ],
        }
        w = Workflow.objects.create(
            congregation=c,
            name="Acompañamiento ministerial",
            definition=standard,
            published=True,
        )
        w2 = Workflow.objects.create(
            congregation=c,
            name="Bienvenida y hospitalidad",
            definition=short,
            published=True,
        )
        questions = [
            {
                "id": "motivation",
                "label": "¿Qué te motiva a servir?",
                "type": "textarea",
                "required": True,
            },
            {
                "id": "skills",
                "label": "Habilidades y talentos",
                "type": "textarea",
                "required": True,
            },
            {
                "id": "availability",
                "label": "Disponibilidad",
                "type": "select",
                "required": True,
                "options": ["Domingo por la mañana", "Sábado", "Entre semana"],
            },
            {"id": "training", "label": "Formación completada", "type": "text"},
            {
                "id": "private",
                "label": "Algo que quieras compartir solo con el equipo",
                "type": "textarea",
                "sensitive": True,
            },
        ]
        q = Questionnaire.objects.create(
            congregation=c,
            name="Conoce a la persona",
            questions=questions,
            published=True,
        )
        Questionnaire.objects.create(
            congregation=c,
            name="Perfil de hospitalidad",
            questions=questions[:3],
            published=True,
        )
        for name in ["Orientación vocacional", "Perfil ministerial"]:
            Questionnaire.objects.create(
                congregation=c,
                name=name,
                is_test=True,
                published=True,
                instructions="Herramienta de orientación; el resultado no decide una integración.",
                questions=[
                    {
                        "id": "service",
                        "label": "¿Con qué actividad te identificas?",
                        "type": "select",
                        "required": True,
                        "options": ["Acompañar", "Organizar", "Enseñar"],
                        "scores": {"Acompañar": 3, "Organizar": 2, "Enseñar": 3},
                    },
                    {
                        "id": "example",
                        "label": "Cuenta una experiencia de servicio",
                        "type": "textarea",
                        "required": True,
                    },
                ],
            )
        opportunities = []
        for name, leader, flow, title in [
            ("Hospitalidad", "Marta Demo", w2, "Un lugar para recibir a otros"),
            ("Formación", "Jorge Demo", w, "Comparte lo que has aprendido"),
            ("Producción", "Elena Demo", w, "Sirve detrás de cada encuentro"),
        ]:
            m = Ministry.objects.create(
                congregation=c,
                name=name,
                leader_name=leader,
                contact="lider@example.test",
                description="Acompañar a las personas de nuestra comunidad.",
                requirements="Compromiso, disposición y entrevista con el líder.",
                capacity=8,
            )
            opportunities.append(
                Opportunity.objects.create(
                    congregation=c,
                    title=title,
                    ministry=m,
                    workflow=flow,
                    questionnaire=q,
                    responsible=members["advisor"],
                    description="Buscamos personas dispuestas a aprender y servir en equipo.",
                    published=True,
                )
            )
        for index, name in enumerate(
            [
                "Valeria",
                "Emilio",
                "Camila",
                "Mateo",
                "Sofía",
                "Diego",
                "Renata",
                "Nicolás",
                "Sara",
                "Leo",
            ]
        ):
            op = opportunities[index % 3]
            app = submit(
                op,
                {
                    "name": name + " Demo",
                    "email": f"persona{index}@example.test",
                    "phone": f"55500000{index:02d}",
                },
                {
                    "motivation": "Acompañar y aprender junto a otros.",
                    "skills": "Trabajo en equipo y organización.",
                    "availability": "Domingo por la mañana",
                    "training": "Curso de fundamentos",
                    "private": "Respuesta ficticia restringida.",
                },
            )
            if index % 3:
                transition(
                    app,
                    members["advisor"],
                    "orientation",
                    "Perfil revisado",
                    app.revision,
                )
                app.refresh_from_db()
                if index % 2:
                    transition(
                        app,
                        members["advisor"],
                        "leader",
                        "Ficha preparada y compartida",
                        app.revision,
                    )
            if index in [2, 5]:
                Application.objects.filter(pk=app.pk).update(
                    due_at=timezone.now() - timedelta(days=2)
                )
        self.stdout.write(
            "Datos ficticios creados. Usuarios: admin/coordinator/advisor/reader/technical@example.test"
        )
        self.stdout.write("Contraseña temporal común de esta demo: " + password)
