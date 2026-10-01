import uuid
from django.conf import settings
from django.db import models
from django.core.exceptions import ValidationError
from django.utils import timezone


class Congregation(models.Model):
    name = models.CharField("Nombre", max_length=160)
    slug = models.SlugField(unique=True)
    active = models.BooleanField(default=True)
    privacy_notice = models.TextField(
        "Aviso de privacidad",
        default="Usaremos tus datos únicamente para orientar y dar seguimiento a tu solicitud de servicio. Contacta a la coordinación para ejercer tus derechos de acceso, rectificación o cancelación.",
    )

    def __str__(self):
        return self.name


class Membership(models.Model):
    ROLES = [
        ("admin", "Administrador"),
        ("coordinator", "Coordinación"),
        ("advisor", "Asesor"),
        ("reader", "Consulta"),
        ("technical", "Técnico"),
    ]
    user = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.PROTECT)
    congregation = models.ForeignKey(Congregation, on_delete=models.PROTECT)
    role = models.CharField(max_length=20, choices=ROLES)
    active = models.BooleanField(default=True)

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["user", "congregation"], name="unique_membership"
            )
        ]

    def __str__(self):
        return self.user.get_full_name() or self.user.username


class Scoped(models.Model):
    congregation = models.ForeignKey(Congregation, on_delete=models.PROTECT)
    created_at = models.DateTimeField(default=timezone.now)
    active = models.BooleanField(default=True)

    class Meta:
        abstract = True


class Ministry(Scoped):
    name = models.CharField("Nombre", max_length=160)
    description = models.TextField("Propósito")
    leader_name = models.CharField("Líder", max_length=160)
    contact = models.CharField("Contacto del líder", max_length=200)
    requirements = models.TextField("Requisitos mínimos")
    capacity = models.PositiveIntegerField("Capacidad", default=10)

    def __str__(self):
        return self.name


class ImmutableVersion(Scoped):
    name = models.CharField("Nombre", max_length=160)
    version = models.PositiveIntegerField(default=1)
    published = models.BooleanField(default=False)

    class Meta:
        abstract = True

    def save(self, *args, **kwargs):
        if self.pk:
            original = type(self).objects.get(pk=self.pk)
            if original.published:
                raise ValidationError(
                    "Las versiones publicadas son inmutables. Duplica para crear una nueva versión."
                )
        super().save(*args, **kwargs)

    def __str__(self):
        return f"{self.name} · v{self.version}"


class Workflow(ImmutableVersion):
    definition = models.JSONField("Definición de flujo", default=dict)


class Questionnaire(ImmutableVersion):
    questions = models.JSONField("Preguntas", default=list)
    is_test = models.BooleanField(default=False)
    instructions = models.TextField("Instrucciones", blank=True)


class Opportunity(Scoped):
    title = models.CharField("Título", max_length=160)
    slug = models.SlugField(unique=True, default=uuid.uuid4)
    ministry = models.ForeignKey(Ministry, on_delete=models.PROTECT)
    workflow = models.ForeignKey(Workflow, on_delete=models.PROTECT)
    questionnaire = models.ForeignKey(Questionnaire, on_delete=models.PROTECT)
    responsible = models.ForeignKey(Membership, on_delete=models.PROTECT)
    description = models.TextField("Descripción")
    published = models.BooleanField(default=False)
    closes_at = models.DateTimeField("Cierre", null=True, blank=True)

    def __str__(self):
        return self.title


class Person(Scoped):
    name = models.CharField("Nombre", max_length=160)
    email = models.EmailField("Correo")
    phone = models.CharField("Teléfono", max_length=30)

    class Meta:
        indexes = [models.Index(fields=["congregation", "email"])]

    def __str__(self):
        return self.name


class Application(Scoped):
    folio = models.CharField(max_length=30, unique=True)
    person = models.ForeignKey(Person, on_delete=models.PROTECT)
    opportunity = models.ForeignKey(Opportunity, on_delete=models.PROTECT)
    workflow = models.ForeignKey(Workflow, on_delete=models.PROTECT)
    questionnaire = models.ForeignKey(Questionnaire, on_delete=models.PROTECT)
    answers = models.JSONField(default=dict)
    state = models.CharField(max_length=80)
    responsible = models.ForeignKey(Membership, on_delete=models.PROTECT)
    next_action = models.CharField("Próxima acción", max_length=240)
    due_at = models.DateTimeField()
    state_entered_at = models.DateTimeField(default=timezone.now)
    last_activity_at = models.DateTimeField(default=timezone.now)
    closed_at = models.DateTimeField(null=True, blank=True)
    pause_started = models.DateTimeField(null=True, blank=True)
    pause_reason = models.TextField(blank=True)
    revision = models.PositiveIntegerField(default=0)
    integration_role = models.CharField(max_length=160, blank=True)
    integration_date = models.DateField(null=True, blank=True)
    consent_at = models.DateTimeField()
    duplicate_of = models.ForeignKey(
        "self", null=True, blank=True, on_delete=models.PROTECT
    )

    class Meta:
        indexes = [
            models.Index(fields=["congregation", "state"]),
            models.Index(fields=["responsible", "due_at"]),
        ]

    @property
    def state_config(self):
        return next(
            (s for s in self.workflow.definition["states"] if s["id"] == self.state), {}
        )

    @property
    def state_label(self):
        return self.state_config.get("label", self.state)

    @property
    def sla(self):
        if self.closed_at:
            return "Finalizada"
        if self.pause_started:
            return "Pausada"
        remaining = (self.due_at - timezone.now()).total_seconds()
        return (
            "Vencida"
            if remaining < 0
            else "Por vencer"
            if remaining < 86400
            else "En tiempo"
        )

    def __str__(self):
        return f"{self.folio} · {self.person.name}"


class Event(models.Model):
    application = models.ForeignKey(
        Application, on_delete=models.PROTECT, related_name="events"
    )
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, on_delete=models.PROTECT
    )
    kind = models.CharField(max_length=40)
    text = models.TextField()
    private = models.BooleanField(default=False)
    metadata = models.JSONField(default=dict)
    created_at = models.DateTimeField(default=timezone.now)

    class Meta:
        ordering = ["-created_at", "-pk"]

    def save(self, *args, **kwargs):
        if self.pk:
            raise ValidationError("El historial no se modifica.")
        super().save(*args, **kwargs)

    def delete(self, *args, **kwargs):
        raise ValidationError("El historial no se elimina.")


class Interview(Scoped):
    application = models.ForeignKey(Application, on_delete=models.PROTECT)
    scheduled_at = models.DateTimeField("Fecha y hora")
    interviewer = models.CharField("Entrevistador", max_length=160)
    location = models.CharField("Lugar / enlace", max_length=240)
    result = models.TextField("Resultado", blank=True)
    status = models.CharField(
        max_length=20,
        choices=[
            ("scheduled", "Programada"),
            ("completed", "Realizada"),
            ("cancelled", "Cancelada"),
        ],
        default="scheduled",
    )


class PublicTask(Scoped):
    token = models.UUIDField(default=uuid.uuid4, unique=True, editable=False)
    application = models.ForeignKey(Application, on_delete=models.PROTECT)
    questionnaire = models.ForeignKey(
        Questionnaire, null=True, blank=True, on_delete=models.PROTECT
    )
    instructions = models.TextField("Instrucciones")
    expires_at = models.DateTimeField()
    answers = models.JSONField(default=dict)
    completed_at = models.DateTimeField(null=True, blank=True)
    score = models.FloatField(null=True, blank=True)


class Attachment(Scoped):
    application = models.ForeignKey(Application, on_delete=models.PROTECT)
    file = models.FileField(upload_to="private/%Y/%m")
    original_name = models.CharField(max_length=160)
    uploaded_by = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.PROTECT)


class Notification(Scoped):
    recipient = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.PROTECT)
    application = models.ForeignKey(Application, null=True, on_delete=models.PROTECT)
    text = models.TextField()
    read_at = models.DateTimeField(null=True, blank=True)
    key = models.CharField(max_length=180, unique=True)

    class Meta:
        ordering = ["-created_at"]


class Audit(Scoped):
    actor = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, on_delete=models.PROTECT
    )
    action = models.CharField(max_length=80)
    object_type = models.CharField(max_length=80)
    object_id = models.CharField(max_length=80)
    detail = models.JSONField(default=dict)

    class Meta:
        ordering = ["-created_at", "-pk"]


class LoginAttempt(models.Model):
    key = models.CharField(max_length=64)
    created_at = models.DateTimeField(default=timezone.now)

    class Meta:
        indexes = [models.Index(fields=["key", "created_at"])]


class Backup(Scoped):
    filename = models.CharField(max_length=180)
    size = models.PositiveBigIntegerField(default=0)
    status = models.CharField(max_length=20, default="complete")
