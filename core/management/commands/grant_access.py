from django.core.management.base import BaseCommand
from django.contrib.auth.models import User
from core.models import Congregation, Membership


class Command(BaseCommand):
    help = "Concede acceso explícito de un usuario existente a otra congregación."

    def add_arguments(self, p):
        p.add_argument("--email", required=True)
        p.add_argument("--slug", required=True)
        p.add_argument(
            "--role", choices=[x[0] for x in Membership.ROLES], required=True
        )

    def handle(self, *args, **o):
        user = User.objects.get(username=o["email"].lower())
        c = Congregation.objects.get(slug=o["slug"])
        Membership.objects.update_or_create(
            user=user, congregation=c, defaults={"role": o["role"], "active": True}
        )
        self.stdout.write("Acceso actualizado.")
