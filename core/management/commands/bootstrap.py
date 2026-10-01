from getpass import getpass
from django.core.management.base import BaseCommand, CommandError
from django.contrib.auth.models import User
from django.contrib.auth.password_validation import validate_password
from django.core.exceptions import ValidationError
from django.db import transaction
from core.models import Congregation, Membership


class Command(BaseCommand):
    help = (
        "Crea la congregación y administrador inicial sin contraseñas predeterminadas."
    )

    def add_arguments(self, p):
        p.add_argument("--email", required=True)
        p.add_argument("--name", default="Administrador")
        p.add_argument("--congregation", required=True)
        p.add_argument("--slug", required=True)

    @transaction.atomic
    def handle(self, *args, **o):
        if User.objects.filter(username=o["email"].lower()).exists():
            raise CommandError("La cuenta ya existe.")
        password = getpass("Contraseña (mínimo 12 caracteres): ")
        if password != getpass("Confirmar contraseña: "):
            raise CommandError("No coinciden.")
        try:
            validate_password(password)
        except ValidationError as e:
            raise CommandError("; ".join(e.messages))
        congregation = Congregation.objects.create(
            name=o["congregation"], slug=o["slug"]
        )
        user = User.objects.create_user(
            username=o["email"].lower(),
            email=o["email"].lower(),
            password=password,
            first_name=o["name"],
        )
        Membership.objects.create(user=user, congregation=congregation, role="admin")
        self.stdout.write("Administrador creado. Inicia sesión con su correo.")
