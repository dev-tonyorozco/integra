from django.core.management.base import BaseCommand
from core.models import Congregation
from core.operations import make_backup


class Command(BaseCommand):
    help = (
        "Respalda la instalación completa; conservar solo para operadores del servidor."
    )

    def handle(self, *args, **options):
        congregation = Congregation.objects.filter(active=True).first()
        if congregation:
            self.stdout.write(make_backup(congregation).filename)
