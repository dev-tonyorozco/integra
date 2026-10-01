import csv
import hashlib
import io
import uuid
from datetime import timedelta
from pathlib import Path
from functools import wraps
from django.conf import settings
from django.contrib import messages
from django.contrib.auth import login
from django.contrib.auth.forms import AuthenticationForm
from django.contrib.auth.models import User
from django.core.exceptions import ValidationError, PermissionDenied
from django.db import transaction
from django.db.models import Count, Q
from django.http import HttpResponse, FileResponse, Http404
from django.shortcuts import render, redirect, get_object_or_404
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_POST
from .models import (
    Congregation,
    Membership,
    Ministry,
    ImmutableVersion,
    Workflow,
    Questionnaire,
    Opportunity,
    Person,
    Application,
    Event,
    Interview,
    PublicTask,
    Attachment,
    Notification,
    Audit,
    LoginAttempt,
    Backup,
)
from .forms import (
    MinistryForm,
    WorkflowForm,
    QuestionnaireForm,
    OpportunityForm,
    DynamicForm,
    InterviewForm,
    UserForm,
    CongregationForm,
)
from .services import (
    authorize,
    validate_workflow,
    validate_questions,
    audit,
    notify,
    submit,
    available_transitions,
    transition,
    reassign,
    pause,
    WRITE_ROLES,
    CONFIG_ROLES,
)


def access(roles=None):
    def decorator(fn):
        @wraps(fn)
        def wrapper(request, *args, **kwargs):
            if not request.user.is_authenticated:
                return redirect("/login/?next=" + request.path)
            memberships = Membership.objects.filter(
                user=request.user, active=True, congregation__active=True
            ).select_related("congregation", "user")
            request.member = (
                memberships.filter(
                    congregation_id=request.session.get("congregation")
                ).first()
                or memberships.first()
            )
            if not request.member:
                raise PermissionDenied("Tu usuario no tiene una congregación activa.")
            if roles:
                authorize(request.member, roles)
            return fn(request, *args, **kwargs)

        return wrapper

    return decorator


def attempt_key(request, namespace):
    return hashlib.sha256(
        (namespace + ":" + request.META.get("REMOTE_ADDR", "unknown")).encode()
    ).hexdigest()


def rate_limited(request, namespace, limit):
    key = attempt_key(request, namespace)
    cutoff = timezone.now() - timedelta(minutes=15)
    return LoginAttempt.objects.filter(key=key, created_at__gte=cutoff).count() >= limit


def signin(request):
    if request.user.is_authenticated:
        return redirect("dashboard")
    form = AuthenticationForm(request, data=request.POST or None)
    if request.method == "POST":
        key = attempt_key(request, "login")
        if rate_limited(request, "login", 10):
            form.add_error(None, "Demasiados intentos. Espera 15 minutos.")
        elif form.is_valid():
            login(request, form.get_user())
            LoginAttempt.objects.filter(key=key).delete()
            return redirect("dashboard")
        else:
            LoginAttempt.objects.create(key=key)
    return render(request, "registration/login.html", {"form": form})


@access()
def dashboard(request):
    if request.member.role == "technical":
        return redirect("audit")
    apps = Application.objects.filter(
        congregation=request.member.congregation, active=True
    ).select_related("person", "opportunity", "responsible__user", "workflow")
    active = apps.filter(closed_at__isnull=True)
    counts = {
        "total": apps.count(),
        "active": active.count(),
        "overdue": active.filter(
            due_at__lt=timezone.now(), pause_started__isnull=True
        ).count(),
        "integrated": apps.exclude(integration_date=None).count(),
    }
    return render(
        request,
        "core/dashboard.html",
        {
            "counts": counts,
            "applications": active.order_by("due_at")[:8],
            "loads": active.values(
                "responsible__user__first_name", "responsible__user__username"
            ).annotate(total=Count("id")),
            "opportunities": Opportunity.objects.filter(
                congregation=request.member.congregation, published=True, active=True
            )[:4],
        },
    )


@require_POST
@access()
def switch(request):
    m = get_object_or_404(
        Membership,
        user=request.user,
        congregation_id=request.POST.get("congregation"),
        active=True,
        congregation__active=True,
    )
    request.session["congregation"] = m.congregation_id
    return redirect("dashboard")


CATALOGS = {
    "ministries": (Ministry, MinistryForm, "Ministerios"),
    "workflows": (Workflow, WorkflowForm, "Flujos"),
    "questionnaires": (Questionnaire, QuestionnaireForm, "Cuestionarios"),
    "tests": (Questionnaire, QuestionnaireForm, "Pruebas"),
    "opportunities": (Opportunity, OpportunityForm, "Convocatorias"),
}


def catalog_queryset(kind, member):
    model = CATALOGS[kind][0]
    qs = model.objects.filter(congregation=member.congregation)
    if kind in {"tests", "questionnaires"}:
        qs = qs.filter(is_test=(kind == "tests"))
    return qs


@access()
def catalog(request, kind):
    if kind not in CATALOGS:
        raise Http404
    if request.member.role == "technical":
        raise PermissionDenied
    items = catalog_queryset(kind, request.member).order_by("-created_at")
    query = request.GET.get("q", "")
    if query:
        items = items.filter(
            **{("title" if kind == "opportunities" else "name") + "__icontains": query}
        )
    from django.core.paginator import Paginator

    return render(
        request,
        "core/catalog.html",
        {
            "title": CATALOGS[kind][2],
            "kind": kind,
            "page": Paginator(items, 20).get_page(request.GET.get("page")),
            "q": query,
        },
    )


@access(CONFIG_ROLES)
def edit_catalog(request, kind, pk=None):
    if kind not in CATALOGS:
        raise Http404
    model, form_class, title = CATALOGS[kind]
    obj = (
        get_object_or_404(catalog_queryset(kind, request.member), pk=pk) if pk else None
    )
    cloning = request.GET.get("copy") == "1"
    if obj and isinstance(obj, ImmutableVersion) and obj.published and not cloning:
        return redirect("catalog", kind=kind)
    initial = {}
    if cloning:
        initial = {
            field.name: getattr(obj, field.name)
            for field in obj._meta.fields
            if field.name in form_class.Meta.fields
        }
        initial["version"] = obj.version + 1
        obj = None
    if kind == "workflows" and not pk:
        initial["definition"] = {
            "initial": "received",
            "states": [
                {
                    "id": "received",
                    "label": "Recibido",
                    "sla_hours": 24,
                    "next_action": "Revisar solicitud",
                },
                {
                    "id": "closed",
                    "label": "Cerrado",
                    "sla_hours": 24,
                    "terminal": True,
                    "outcome": "closed",
                },
            ],
            "transitions": [
                {
                    "from": "received",
                    "to": "closed",
                    "roles": ["admin", "coordinator", "advisor"],
                }
            ],
        }
    if kind in {"tests", "questionnaires"} and not pk:
        initial["questions"] = [
            {
                "id": "motivation",
                "label": "¿Por qué quieres servir?",
                "type": "textarea",
                "required": True,
            }
        ]
    kwargs = {"member": request.member} if kind == "opportunities" else {}
    form = form_class(request.POST or None, instance=obj, initial=initial, **kwargs)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            saved = form.save(commit=False)
            saved.congregation = request.member.congregation
            if kind in {"tests", "questionnaires"}:
                saved.is_test = kind == "tests"
            saved.save()
            audit(request.member, "catalog.saved", saved)
        messages.success(request, "Cambios guardados.")
        return redirect("catalog", kind=kind)
    return render(
        request,
        "core/form.html",
        {
            "form": form,
            "title": ("Editar " if obj else "Crear ") + title.lower(),
            "kind": kind,
            "builder": kind if kind in {"workflows", "questionnaires", "tests"} else "",
        },
    )


@require_POST
@access(CONFIG_ROLES)
def publish(request, kind, pk):
    if kind not in CATALOGS:
        raise Http404
    obj = get_object_or_404(catalog_queryset(kind, request.member), pk=pk)
    try:
        with transaction.atomic():
            if isinstance(obj, Workflow):
                validate_workflow(obj.definition)
            elif isinstance(obj, Questionnaire):
                validate_questions(obj.questions)
            elif isinstance(obj, Opportunity):
                if (
                    not obj.workflow.published
                    or not obj.questionnaire.published
                    or not obj.ministry.active
                    or not obj.ministry.contact
                    or not obj.ministry.requirements
                    or not obj.responsible.active
                ):
                    raise ValidationError(
                        "La convocatoria requiere ministerio disponible, contacto, requisitos, versiones publicadas y responsable."
                    )
                if any(
                    x.congregation_id != obj.congregation_id
                    for x in [
                        obj.workflow,
                        obj.questionnaire,
                        obj.ministry,
                        obj.responsible,
                    ]
                ):
                    raise ValidationError("Datos de otra congregación.")
            else:
                raise ValidationError("Este catálogo no se publica.")
            if obj.published:
                raise ValidationError("Ya está publicado.")
            obj.published = True
            obj.save()
            audit(request.member, "catalog.published", obj)
        messages.success(request, "Publicación completada.")
    except ValidationError as e:
        messages.error(request, " ".join(e.messages))
    return redirect("catalog", kind=kind)


@access()
def applications(request):
    if request.member.role == "technical":
        raise PermissionDenied
    apps = Application.objects.filter(
        congregation=request.member.congregation, active=True
    ).select_related("person", "opportunity__ministry", "responsible__user", "workflow")
    q = request.GET.get("q", "")
    if q:
        apps = apps.filter(
            Q(folio__icontains=q)
            | Q(person__name__icontains=q)
            | Q(person__email__icontains=q)
        )
    if request.GET.get("mine"):
        apps = apps.filter(responsible=request.member)
    if request.GET.get("overdue"):
        apps = apps.filter(
            closed_at__isnull=True,
            due_at__lt=timezone.now(),
            pause_started__isnull=True,
        )
    if request.GET.get("state"):
        apps = apps.filter(state=request.GET["state"])
    if request.GET.get("ministry"):
        apps = apps.filter(opportunity__ministry_id=request.GET["ministry"])
    from django.core.paginator import Paginator

    return render(
        request,
        "core/applications.html",
        {
            "page": Paginator(apps.order_by("-created_at"), 20).get_page(
                request.GET.get("page")
            ),
            "q": q,
            "ministries": Ministry.objects.filter(
                congregation=request.member.congregation
            ),
            "states": sorted(set(apps.values_list("state", flat=True))),
        },
    )


@access()
def application_detail(request, pk):
    if request.member.role == "technical":
        raise PermissionDenied
    app = get_object_or_404(
        Application.objects.select_related(
            "person",
            "workflow",
            "questionnaire",
            "opportunity__ministry",
            "responsible__user",
        ),
        pk=pk,
        congregation=request.member.congregation,
    )
    form_error = ""
    if request.method == "POST":
        try:
            authorize(request.member)
            action = request.POST.get("action")
            if action == "transition":
                date = request.POST.get("integration_date")
                from datetime import date as Date

                owner = (
                    get_object_or_404(
                        Membership,
                        pk=request.POST["responsible"],
                        congregation=request.member.congregation,
                    )
                    if request.POST.get("responsible")
                    else None
                )
                transition(
                    app,
                    request.member,
                    request.POST.get("destination"),
                    request.POST.get("comment", ""),
                    int(request.POST.get("revision", -1)),
                    leader_decision=request.POST.get("leader_decision", ""),
                    integration_role=request.POST.get("integration_role", ""),
                    integration_date=Date.fromisoformat(date) if date else None,
                    responsible=owner,
                )
            elif action == "note":
                text = request.POST.get("text", "").strip()
                if not text or len(text) > 10000:
                    raise ValidationError(
                        "Escribe una nota de hasta 10,000 caracteres."
                    )
                with transaction.atomic():
                    Event.objects.create(
                        application=app,
                        actor=request.user,
                        kind="note",
                        text=text,
                        private=request.POST.get("private") == "on",
                    )
                    app.last_activity_at = timezone.now()
                    app.save(update_fields=["last_activity_at"])
            elif action == "reassign":
                owner = get_object_or_404(
                    Membership,
                    pk=request.POST.get("responsible"),
                    congregation=request.member.congregation,
                )
                reassign(app, request.member, owner, request.POST.get("reason", ""))
            elif action == "pause":
                pause(app, request.member, request.POST.get("reason", ""))
            elif action == "task":
                questionnaire = (
                    get_object_or_404(
                        Questionnaire,
                        pk=request.POST["questionnaire"],
                        congregation=request.member.congregation,
                        published=True,
                    )
                    if request.POST.get("questionnaire")
                    else None
                )
                instructions = request.POST.get("instructions", "").strip()
                if not instructions:
                    raise ValidationError("Indica las instrucciones.")
                with transaction.atomic():
                    task = PublicTask.objects.create(
                        congregation=app.congregation,
                        application=app,
                        questionnaire=questionnaire,
                        instructions=instructions,
                        expires_at=timezone.now() + timedelta(days=10),
                    )
                    Event.objects.create(
                        application=app,
                        actor=request.user,
                        kind="task",
                        text="Se generó un enlace seguro para información o prueba.",
                        metadata={"task": task.pk},
                    )
                    audit(request.member, "task.created", task)
            elif action == "leader":
                decision = request.POST.get("decision", "").strip()
                if not decision:
                    raise ValidationError("Documenta la respuesta del líder.")
                Event.objects.create(
                    application=app,
                    actor=request.user,
                    kind="leader",
                    text=decision,
                    metadata={"leader": app.opportunity.ministry.leader_name},
                )
            elif action == "archive":
                authorize(request.member, CONFIG_ROLES)
                if not app.closed_at:
                    raise ValidationError("Cierra la solicitud antes de archivarla.")
                app.active = False
                app.save(update_fields=["active"])
                audit(request.member, "application.archived", app)
                return redirect("applications")
            else:
                raise ValidationError("Acción inválida.")
            messages.success(request, "Acción registrada.")
            return redirect("application", pk=pk)
        except (ValidationError, ValueError) as e:
            form_error = (
                " ".join(e.messages)
                if isinstance(e, ValidationError)
                else "Datos inválidos."
            )
    answers = [
        {"label": q["label"], "value": app.answers.get(q["id"], "—")}
        for q in app.questionnaire.questions
        if request.member.role != "reader" or not q.get("sensitive")
    ]
    events = app.events.all()
    if request.member.role == "reader":
        events = events.filter(private=False)
    return render(
        request,
        "core/application.html",
        {
            "application": app,
            "answers": answers,
            "events": events,
            "transitions": available_transitions(app, request.member),
            "owners": Membership.objects.filter(
                congregation=app.congregation,
                active=True,
                user__is_active=True,
                role__in=WRITE_ROLES,
            ),
            "tests": Questionnaire.objects.filter(
                congregation=app.congregation, published=True, is_test=True
            ),
            "tasks": PublicTask.objects.filter(application=app)
            if request.member.role != "reader"
            else [],
            "interviews": Interview.objects.filter(application=app),
            "attachments": Attachment.objects.filter(application=app),
            "form_error": form_error,
        },
    )


@access(WRITE_ROLES)
def interview(request, pk, interview_pk=None):
    app = get_object_or_404(
        Application, pk=pk, congregation=request.member.congregation
    )
    obj = (
        get_object_or_404(Interview, pk=interview_pk, application=app)
        if interview_pk
        else None
    )
    form = InterviewForm(request.POST or None, instance=obj)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            item = form.save(commit=False)
            item.congregation = app.congregation
            item.application = app
            item.save()
            Event.objects.create(
                application=app,
                actor=request.user,
                kind="interview",
                text=f"Entrevista {item.get_status_display()}: {item.scheduled_at:%d/%m/%Y %H:%M}. {item.result}",
            )
            audit(request.member, "interview.saved", item)
        return redirect("application", pk=pk)
    return render(request, "core/form.html", {"title": "Entrevista", "form": form})


def public_home(request, slug):
    congregation = get_object_or_404(Congregation, slug=slug, active=True)
    opportunities = (
        Opportunity.objects.filter(
            congregation=congregation,
            published=True,
            active=True,
            ministry__active=True,
        )
        .filter(Q(closes_at__isnull=True) | Q(closes_at__gt=timezone.now()))
        .select_related("ministry")
    )
    return render(
        request,
        "core/public_home.html",
        {"congregation": congregation, "opportunities": opportunities, "public": True},
    )


def public_apply(request, slug):
    opportunity = get_object_or_404(
        Opportunity.objects.select_related(
            "congregation", "questionnaire", "workflow", "ministry", "responsible"
        ),
        slug=slug,
        published=True,
        active=True,
        congregation__active=True,
        ministry__active=True,
    )
    if opportunity.closes_at and opportunity.closes_at <= timezone.now():
        return render(request, "core/unavailable.html", {"public": True}, status=410)
    form = DynamicForm(
        request.POST or None,
        questions=opportunity.questionnaire.questions,
        identity=True,
    )
    if request.method == "POST" and form.is_valid():
        if rate_limited(request, "submit", 20):
            form.add_error(None, "Demasiadas solicitudes. Intenta más tarde.")
        elif form.cleaned_data.get("website"):
            form.add_error(None, "No pudimos registrar la solicitud.")
        else:
            try:
                app = submit(opportunity, form.cleaned_data, form.answers())
                LoginAttempt.objects.create(key=attempt_key(request, "submit"))
                request.session["receipt"] = app.folio
                return redirect("receipt")
            except ValidationError as e:
                form.add_error(None, e)
    return render(
        request,
        "core/public_apply.html",
        {"public": True, "opportunity": opportunity, "form": form},
    )


def receipt(request):
    folio = request.session.get("receipt")
    if not folio:
        raise Http404
    return render(request, "core/receipt.html", {"public": True, "folio": folio})


def public_task(request, token):
    task = get_object_or_404(
        PublicTask.objects.select_related("application", "questionnaire"),
        token=token,
        active=True,
        congregation__active=True,
    )
    if task.expires_at <= timezone.now() or task.completed_at:
        return render(request, "core/unavailable.html", {"public": True}, status=410)
    questions = (
        task.questionnaire.questions
        if task.questionnaire
        else [
            {
                "id": "response",
                "label": "Información solicitada",
                "type": "textarea",
                "required": True,
            }
        ]
    )
    form = DynamicForm(request.POST or None, questions=questions)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            task = PublicTask.objects.select_for_update().get(pk=task.pk)
            if task.completed_at or task.expires_at <= timezone.now():
                return render(
                    request, "core/unavailable.html", {"public": True}, status=410
                )
            task.answers = form.answers()
            task.completed_at = timezone.now()
            total = 0
            for q in questions:
                value = task.answers.get(q["id"])
                for item in value if isinstance(value, list) else [value]:
                    total += q.get("scores", {}).get(str(item), 0)
            task.score = (
                total if task.questionnaire and task.questionnaire.is_test else None
            )
            task.save()
            Event.objects.create(
                application=task.application,
                kind="task.completed",
                text="Se recibió la información o prueba solicitada.",
                metadata={"task": task.pk},
            )
            notify(
                task.application,
                task.application.responsible,
                "Información recibida: " + task.application.folio,
                "task:" + str(task.pk),
            )
        return render(request, "core/task_complete.html", {"public": True})
    return render(
        request,
        "core/form.html",
        {
            "public": True,
            "title": "Información para tu solicitud",
            "instructions": task.instructions,
            "form": form,
        },
    )


@access()
def people(request):
    if request.member.role == "technical":
        raise PermissionDenied
    items = Person.objects.filter(
        congregation=request.member.congregation, active=True
    ).annotate(total=Count("application"))
    if request.GET.get("q"):
        items = items.filter(
            Q(name__icontains=request.GET["q"]) | Q(email__icontains=request.GET["q"])
        )
    from django.core.paginator import Paginator

    return render(
        request,
        "core/people.html",
        {
            "page": Paginator(items.order_by("name"), 20).get_page(
                request.GET.get("page")
            )
        },
    )


@access(CONFIG_ROLES)
def users(request):
    form = UserForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            user = User.objects.create_user(
                username=form.cleaned_data["email"],
                email=form.cleaned_data["email"],
                password=form.cleaned_data["password"],
                first_name=form.cleaned_data["name"],
            )
            item = Membership.objects.create(
                user=user,
                congregation=request.member.congregation,
                role=form.cleaned_data["role"],
            )
            audit(request.member, "user.created", item)
        messages.success(
            request, "Usuario creado. Comparte la contraseña por un canal privado."
        )
        return redirect("users")
    return render(
        request,
        "core/users.html",
        {
            "form": form,
            "users": Membership.objects.filter(
                congregation=request.member.congregation
            ).select_related("user"),
        },
    )


@require_POST
@access(CONFIG_ROLES)
def user_toggle(request, pk):
    item = get_object_or_404(
        Membership, pk=pk, congregation=request.member.congregation
    )
    if item.pk == request.member.pk:
        messages.error(request, "No puedes desactivar tu propio acceso.")
    elif (
        item.active
        and Application.objects.filter(
            responsible=item, closed_at__isnull=True, active=True
        ).exists()
    ):
        messages.error(
            request,
            "Reasigna sus solicitudes activas antes de desactivar al responsable.",
        )
    elif (
        item.role == "admin"
        and item.active
        and Membership.objects.filter(
            congregation=item.congregation, active=True, role="admin"
        ).count()
        == 1
    ):
        messages.error(request, "Debe quedar un administrador activo.")
    else:
        item.active = not item.active
        item.save()
        audit(request.member, "user.access_changed", item)
    return redirect("users")


@access({"admin"})
def congregation_create(request):
    form = CongregationForm(request.POST or None)
    if request.method == "POST" and form.is_valid():
        with transaction.atomic():
            congregation = form.save()
            Membership.objects.create(
                user=request.user, congregation=congregation, role="admin"
            )
            audit(request.member, "congregation.created", congregation)
        request.session["congregation"] = congregation.pk
        return redirect("dashboard")
    return render(
        request, "core/form.html", {"form": form, "title": "Nueva congregación"}
    )


@access()
def notifications(request):
    items = Notification.objects.filter(
        congregation=request.member.congregation, recipient=request.user
    )
    if request.method == "POST":
        items.filter(read_at__isnull=True).update(read_at=timezone.now())
        return redirect("notifications")
    return render(request, "core/notifications.html", {"items": items[:100]})


@access({"admin", "coordinator", "technical"})
def audit_log(request):
    items = Audit.objects.filter(
        congregation=request.member.congregation
    ).select_related("actor")
    if request.GET.get("q"):
        items = items.filter(action__icontains=request.GET["q"])
    from django.core.paginator import Paginator

    return render(
        request,
        "core/audit.html",
        {"page": Paginator(items, 30).get_page(request.GET.get("page"))},
    )


@access()
def reports(request):
    if request.member.role == "technical":
        raise PermissionDenied
    apps = Application.objects.filter(congregation=request.member.congregation)
    now = timezone.now()
    rows = apps.select_related(
        "person", "workflow", "responsible__user", "opportunity__ministry"
    )
    if request.GET.get("export") == "csv":
        response = HttpResponse(content_type="text/csv; charset=utf-8")
        response["Content-Disposition"] = (
            'attachment; filename="integra-solicitudes.csv"'
        )
        response.write("\ufeff")
        writer = csv.writer(response)
        writer.writerow(
            [
                "Folio",
                "Nombre",
                "Ministerio",
                "Estado",
                "Responsable",
                "SLA",
                "Fecha alta",
                "Fecha límite",
                "Próxima acción",
            ]
        )
        for app in rows.iterator():
            values = [
                app.folio,
                app.person.name,
                app.opportunity.ministry.name,
                app.state_label,
                str(app.responsible),
                app.sla,
                app.created_at.isoformat(),
                app.due_at.isoformat(),
                app.next_action,
            ]
            writer.writerow(
                [
                    ("'" + str(v))
                    if str(v).startswith(("=", "+", "-", "@", "\t", "\r"))
                    else v
                    for v in values
                ]
            )
        audit(request.member, "report.exported", request.member)
        return response
    closed = list(apps.exclude(closed_at=None))
    durations = sorted(
        (a.closed_at - a.created_at).total_seconds() / 86400 for a in closed
    )
    ontime = sum(1 for a in closed if a.closed_at <= a.due_at)
    phase_counts = {}
    for event in Event.objects.filter(
        application__congregation=request.member.congregation, kind="transition"
    ):
        state = event.metadata.get("from")
        due = event.metadata.get("previous_due_at")
        if not due:
            continue
        from django.utils.dateparse import parse_datetime

        row = phase_counts.setdefault(state, {"name": state, "total": 0, "ontime": 0})
        row["total"] += 1
        row["ontime"] += int(event.created_at <= parse_datetime(due))
    phase_rows = [
        dict(row, percent=round(row["ontime"] / row["total"] * 100))
        for row in phase_counts.values()
    ]
    return render(
        request,
        "core/reports.html",
        {
            "phase_sla": phase_rows,
            "funnel": apps.values("state").annotate(total=Count("id")),
            "loads": apps.filter(closed_at=None)
            .values("responsible__user__first_name", "responsible__user__username")
            .annotate(total=Count("id")),
            "ministries": Ministry.objects.filter(
                congregation=request.member.congregation
            ).annotate(
                total=Count(
                    "opportunity__application",
                    filter=Q(opportunity__application__closed_at=None),
                )
            ),
            "cycle": round(sum(durations) / len(durations), 1) if durations else 0,
            "median": round(__import__("statistics").median(durations), 1)
            if durations
            else 0,
            "ontime": round(ontime / len(closed) * 100) if closed else 0,
            "stale": rows.filter(
                closed_at=None, last_activity_at__lt=now - timedelta(days=7)
            )[:20],
            "closed": len(closed),
            "integrated": apps.exclude(integration_date=None).count(),
        },
    )


@access()
def pdf(request, pk):
    if request.member.role == "technical":
        raise PermissionDenied
    app = get_object_or_404(
        Application, pk=pk, congregation=request.member.congregation
    )
    from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer
    from reportlab.lib.styles import getSampleStyleSheet
    from xml.sax.saxutils import escape

    buffer = io.BytesIO()
    styles = getSampleStyleSheet()
    story = []

    def line(text, style="BodyText"):
        story.append(Paragraph(escape(str(text)).replace("\n", "<br/>"), styles[style]))
        story.append(Spacer(1, 8))

    line("INTEGRA · Ficha de canalización", "Title")
    line(app.folio, "Heading2")
    for text in [
        app.person.name,
        app.person.email,
        app.person.phone,
        app.opportunity.title,
        app.state_label,
        "Responsable: " + str(app.responsible),
        "Próxima acción: " + app.next_action,
        "Límite: " + str(app.due_at.date()),
    ]:
        line(text)
    for question in app.questionnaire.questions:
        if not question.get("sensitive"):
            line(question["label"] + ": " + str(app.answers.get(question["id"], "")))
    line("Decisión del líder", "Heading2")
    for event in app.events.filter(kind__in=["leader", "transition"], private=False):
        line(f"{event.created_at:%d/%m/%Y} · {event.text}")
    SimpleDocTemplate(buffer).build(story)
    buffer.seek(0)
    audit(request.member, "summary.downloaded", app)
    return FileResponse(buffer, as_attachment=True, filename=app.folio + ".pdf")


@require_POST
@access(WRITE_ROLES)
def upload(request, pk):
    app = get_object_or_404(
        Application, pk=pk, congregation=request.member.congregation
    )
    f = request.FILES.get("file")
    try:
        if not f or f.size > 5 * 1024 * 1024 or f.size == 0:
            raise ValidationError("El archivo debe medir entre 1 byte y 5 MB.")
        header = f.read(16)
        f.seek(0)
        ext = Path(f.name).suffix.lower()
        if (
            ext == ".pdf"
            and header.startswith(b"%PDF-")
            and f.content_type == "application/pdf"
        ):
            content = f
        elif ext in {".jpg", ".jpeg", ".png"} and f.content_type in {
            "image/jpeg",
            "image/png",
        }:
            from PIL import Image
            from django.core.files.base import ContentFile

            img = Image.open(f)
            img.verify()
            f.seek(0)
            img = Image.open(f)
            if img.width * img.height > 20000000:
                raise ValidationError("La imagen excede las dimensiones permitidas.")
            out = io.BytesIO()
            img.convert("RGB").save(out, format="JPEG", quality=90)
            content = ContentFile(out.getvalue())
            ext = ".jpg"
        else:
            raise ValidationError("Solo PDF, JPG y PNG con contenido válido.")
        item = Attachment(
            congregation=app.congregation,
            application=app,
            original_name=Path(f.name).name[:160],
            uploaded_by=request.user,
        )
        item.file.save(uuid.uuid4().hex + ext, content)
        audit(request.member, "attachment.uploaded", item)
        Event.objects.create(
            application=app,
            actor=request.user,
            kind="attachment",
            text="Se agregó un documento autorizado.",
        )
        messages.success(request, "Archivo guardado de forma privada.")
    except (ValidationError, OSError, ValueError) as e:
        messages.error(
            request,
            " ".join(e.messages)
            if isinstance(e, ValidationError)
            else "El archivo no es válido.",
        )
    return redirect("application", pk=pk)


@access()
def download(request, pk):
    if request.member.role == "technical":
        raise PermissionDenied
    item = get_object_or_404(
        Attachment, pk=pk, congregation=request.member.congregation
    )
    audit(request.member, "attachment.downloaded", item)
    response = FileResponse(
        item.file.open("rb"), as_attachment=True, filename=item.original_name
    )
    response["X-Content-Type-Options"] = "nosniff"
    return response


@access({"admin", "technical"})
def backups(request):
    if request.method == "POST":
        from .operations import make_backup

        make_backup(request.member.congregation, request.member)
        messages.success(request, "Respaldo creado: base de datos y adjuntos.")
        return redirect("backups")
    return render(
        request,
        "core/backups.html",
        {
            "items": Backup.objects.filter(
                congregation=request.member.congregation
            ).order_by("-created_at")[:30]
        },
    )


def health(request):
    from django.db import connection

    with connection.cursor() as cur:
        cur.execute("SELECT 1")
    return HttpResponse("ok", content_type="text/plain")


@access()
def preview_catalog(request, kind, pk):
    if (
        kind not in {"workflows", "questionnaires", "tests"}
        or request.member.role == "technical"
    ):
        raise Http404
    obj = get_object_or_404(catalog_queryset(kind, request.member), pk=pk)
    if kind == "workflows":
        return render(request, "core/workflow_preview.html", {"workflow": obj})
    form = DynamicForm(request.POST or None, questions=obj.questions)
    if request.method == "POST" and form.is_valid():
        messages.success(
            request, "Respuestas válidas. Esta vista previa no guarda información."
        )
    return render(
        request,
        "core/form.html",
        {
            "title": "Vista previa · " + obj.name,
            "instructions": obj.instructions,
            "form": form,
        },
    )


@access()
def opportunity_qr(request, pk):
    if request.member.role == "technical":
        raise PermissionDenied
    op = get_object_or_404(
        Opportunity, pk=pk, congregation=request.member.congregation, published=True
    )
    import qrcode

    buffer = io.BytesIO()
    qrcode.make(settings.SITE_URL + reverse("public_apply", args=[op.slug])).save(
        buffer, format="PNG"
    )
    buffer.seek(0)
    return FileResponse(
        buffer, as_attachment=True, filename="integra-convocatoria-qr.png"
    )
