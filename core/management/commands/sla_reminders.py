from datetime import timedelta
from django.core.management.base import BaseCommand
from django.utils import timezone
from core.models import Application, Membership, LoginAttempt
from core.services import notify


class Command(BaseCommand):
    help = "Genera avisos idempotentes de SLA y escalamiento en la aplicación."

    def handle(self, *args, **options):
        now = timezone.now()
        count = 0
        for app in Application.objects.filter(
            closed_at=None, pause_started=None, active=True
        ).select_related("responsible", "congregation"):
            if app.due_at <= now + timedelta(hours=24):
                category = "overdue" if app.due_at < now else "upcoming"
                key = f"sla:{app.pk}:{app.due_at.isoformat()}:{category}"
                notify(app, app.responsible, f"{app.folio}: {app.sla}", key)
                count += 1
                if category == "overdue":
                    for member in Membership.objects.filter(
                        congregation=app.congregation,
                        role__in=["coordinator", "admin"],
                        active=True,
                        user__is_active=True,
                    ):
                        notify(
                            app,
                            member,
                            f"Escalamiento: {app.folio} está vencida.",
                            key + f":escalate:{member.pk}",
                        )
        LoginAttempt.objects.filter(created_at__lt=now - timedelta(days=1)).delete()
        self.stdout.write(f"{count} solicitudes revisadas; avisos sin duplicados.")
