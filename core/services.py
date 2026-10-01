import uuid
from datetime import timedelta
from django.core.exceptions import ValidationError, PermissionDenied
from django.db import transaction
from django.utils import timezone
from .models import Application, Person, Event, Notification, Audit

WRITE_ROLES = {"admin", "coordinator", "advisor"}
CONFIG_ROLES = {"admin", "coordinator"}


def authorize(member, roles=WRITE_ROLES):
    if (
        not member.active
        or not member.user.is_active
        or not member.congregation.active
        or member.role not in roles
    ):
        raise PermissionDenied("No tienes permiso para esta acción.")


def scoped(member, obj):
    if member.congregation_id != obj.congregation_id:
        raise PermissionDenied


def validate_workflow(definition):
    if not isinstance(definition, dict):
        raise ValidationError("El flujo debe ser un objeto.")
    states, transitions = (
        definition.get("states", []),
        definition.get("transitions", []),
    )
    if not states or not isinstance(states, list) or len(states) > 100:
        raise ValidationError("Define entre 1 y 100 estados.")
    ids = set()
    for s in states:
        if (
            not isinstance(s, dict)
            or not isinstance(s.get("id"), str)
            or not s["id"]
            or len(s["id"]) > 80
            or not s.get("label")
            or s["id"] in ids
        ):
            raise ValidationError("Cada estado requiere identificador único y nombre.")
        ids.add(s["id"])
        if s.get("outcome", "") not in {"", "integrated", "reorient", "closed"}:
            raise ValidationError("Resultado inválido.")
        if s.get("outcome") in {"integrated", "closed"} and not s.get("terminal"):
            raise ValidationError("Un resultado final requiere estado terminal.")
        hours = s.get("sla_hours", 24)
        if not isinstance(hours, int) or not 1 <= hours <= 8760:
            raise ValidationError("SLA fuera de rango.")
    initial = definition.get("initial")
    if initial not in ids:
        raise ValidationError("Estado inicial inválido.")
    if next(s for s in states if s["id"] == initial).get("terminal"):
        raise ValidationError("El estado inicial no puede ser terminal.")
    pairs = set()
    if not isinstance(transitions, list) or len(transitions) > 500:
        raise ValidationError("Transiciones inválidas.")
    for t in transitions:
        if (
            not isinstance(t, dict)
            or t.get("from") not in ids
            or t.get("to") not in ids
            or t["from"] == t["to"]
        ):
            raise ValidationError("Transición inválida.")
        pair = (t["from"], t["to"])
        if pair in pairs:
            raise ValidationError("Transición duplicada.")
        pairs.add(pair)
        roles = t.get("roles", list(WRITE_ROLES))
        if not isinstance(roles, list) or not roles or set(roles) - WRITE_ROLES:
            raise ValidationError("Roles de transición inválidos.")
        if next(s for s in states if s["id"] == t["from"]).get("terminal"):
            raise ValidationError("Los estados terminales no tienen salidas.")
    reachable = {initial}
    while True:
        expanded = reachable | {t["to"] for t in transitions if t["from"] in reachable}
        if expanded == reachable:
            break
        reachable = expanded
    if ids - reachable:
        raise ValidationError("Hay estados sin ruta desde el inicio.")
    if not any(s.get("terminal") for s in states):
        raise ValidationError("Define al menos un estado terminal.")


def validate_questions(questions):
    if not isinstance(questions, list) or not 1 <= len(questions) <= 100:
        raise ValidationError("Define entre 1 y 100 preguntas.")
    ids = set()
    for q in questions:
        if (
            not isinstance(q, dict)
            or not isinstance(q.get("id"), str)
            or not q["id"]
            or q["id"] in ids
            or not q.get("label")
        ):
            raise ValidationError("Cada pregunta requiere clave única y etiqueta.")
        ids.add(q["id"])
        if q.get("type", "text") not in {
            "text",
            "textarea",
            "email",
            "number",
            "date",
            "select",
            "multiselect",
            "boolean",
        }:
            raise ValidationError("Tipo de pregunta no admitido.")
        if q.get("type") in {"select", "multiselect"}:
            options = q.get("options")
            if (
                not isinstance(options, list)
                or not options
                or any(not isinstance(o, str) for o in options)
                or len(options) != len(set(options))
            ):
                raise ValidationError("Las opciones deben ser textos únicos.")
        scores = q.get("scores", {})
        if not isinstance(scores, dict) or any(
            not isinstance(v, (int, float)) or abs(v) > 10000 for v in scores.values()
        ):
            raise ValidationError("Ponderaciones inválidas.")


def deadline(state, start=None):
    start = start or timezone.now()
    hours = state.get("sla_hours", 24)
    if not state.get("business_days"):
        return start + timedelta(hours=hours)
    result = start
    for _ in range(max(1, (hours + 23) // 24)):
        result += timedelta(days=1)
        while result.weekday() > 4:
            result += timedelta(days=1)
    return result


def audit(member, action, obj, detail=None):
    Audit.objects.create(
        congregation=member.congregation,
        actor=member.user,
        action=action,
        object_type=type(obj).__name__,
        object_id=str(obj.pk),
        detail=detail or {},
    )


def notify(app, member, text, key):
    Notification.objects.get_or_create(
        key=key,
        defaults={
            "congregation": app.congregation,
            "recipient": member.user,
            "application": app,
            "text": text,
        },
    )


@transaction.atomic
def submit(opportunity, cleaned, answers):
    opportunity.refresh_from_db()
    now = timezone.now()
    if any(
        x.congregation_id != opportunity.congregation_id
        for x in [
            opportunity.ministry,
            opportunity.workflow,
            opportunity.questionnaire,
            opportunity.responsible,
        ]
    ):
        raise ValidationError("La convocatoria contiene datos de otra congregación.")
    if (
        not opportunity.active
        or not opportunity.published
        or not opportunity.congregation.active
        or not opportunity.ministry.active
        or (opportunity.closes_at and opportunity.closes_at <= now)
    ):
        raise ValidationError("La convocatoria está cerrada.")
    if not opportunity.responsible.active or not opportunity.responsible.user.is_active:
        raise ValidationError("La convocatoria requiere responsable activo.")
    validate_workflow(opportunity.workflow.definition)
    if not opportunity.workflow.published or not opportunity.questionnaire.published:
        raise ValidationError("La convocatoria no está lista.")
    email = cleaned["email"].strip().lower()
    person = Person.objects.filter(
        congregation=opportunity.congregation, email=email, phone=cleaned["phone"]
    ).first()
    if not person:
        person = Person.objects.create(
            congregation=opportunity.congregation,
            name=cleaned["name"],
            email=email,
            phone=cleaned["phone"],
        )
    duplicate = (
        Application.objects.filter(
            congregation=opportunity.congregation, person__email=email
        )
        .order_by("-created_at")
        .first()
    )
    state = next(
        s
        for s in opportunity.workflow.definition["states"]
        if s["id"] == opportunity.workflow.definition["initial"]
    )
    app = Application.objects.create(
        congregation=opportunity.congregation,
        folio="INT-" + now.strftime("%y") + "-" + uuid.uuid4().hex[:12].upper(),
        person=person,
        opportunity=opportunity,
        workflow=opportunity.workflow,
        questionnaire=opportunity.questionnaire,
        answers=answers,
        state=state["id"],
        responsible=opportunity.responsible,
        next_action=state.get("next_action", "Revisar solicitud"),
        due_at=deadline(state),
        consent_at=now,
        duplicate_of=duplicate,
    )
    Event.objects.create(
        application=app,
        kind="received",
        text="Solicitud recibida. Aviso de privacidad aceptado.",
        metadata={"origin": "public", "duplicate": bool(duplicate)},
    )
    notify(app, app.responsible, f"Nueva solicitud {app.folio}", f"received:{app.pk}")
    return app


def available_transitions(app, member):
    labels = {
        state["id"]: state["label"] for state in app.workflow.definition["states"]
    }
    return [
        dict(edge, target_label=labels[edge["to"]])
        for edge in app.workflow.definition["transitions"]
        if edge["from"] == app.state
        and member.role in edge.get("roles", list(WRITE_ROLES))
    ]


@transaction.atomic
def transition(
    app,
    member,
    destination,
    comment,
    revision,
    leader_decision="",
    integration_role="",
    integration_date=None,
    responsible=None,
):
    authorize(member)
    scoped(member, app)
    app = Application.objects.select_for_update().get(pk=app.pk)
    if app.revision != revision:
        raise ValidationError("La solicitud cambió. Recarga antes de continuar.")
    if app.pause_started:
        raise ValidationError("Reanuda el SLA antes de cambiar de estado.")
    edge = next(
        (t for t in available_transitions(app, member) if t["to"] == destination), None
    )
    if not edge or app.closed_at:
        raise ValidationError("Transición no permitida.")
    if not comment.strip():
        raise ValidationError("Registra el comentario de la transición.")
    target = next(
        s for s in app.workflow.definition["states"] if s["id"] == destination
    )
    outcome = target.get("outcome")
    if outcome in {"integrated", "reorient"} and not leader_decision.strip():
        raise ValidationError(
            "Registra la decisión del líder; INTEGRA no decide la aceptación."
        )
    if outcome == "integrated" and (
        not integration_role.strip() or not integration_date
    ):
        raise ValidationError("Registra función y fecha de integración.")
    if outcome == "reorient" and responsible is None:
        raise ValidationError("Selecciona responsable para la reorientación.")
    if responsible:
        scoped(member, responsible)
        if (
            not responsible.active
            or not responsible.user.is_active
            or responsible.role not in WRITE_ROLES
        ):
            raise ValidationError("Responsable inválido.")
    old_state, old_responsible, old_due = app.state, app.responsible_id, app.due_at
    app.state = destination
    app.state_entered_at = timezone.now()
    app.last_activity_at = timezone.now()
    app.next_action = target.get("next_action", "Dar seguimiento")
    app.due_at = deadline(target)
    if responsible:
        app.responsible = responsible
    if target.get("terminal"):
        app.closed_at = timezone.now()
    if outcome == "integrated":
        app.integration_role = integration_role
        app.integration_date = integration_date
    app.revision += 1
    app.save()
    Event.objects.create(
        application=app,
        actor=member.user,
        kind="transition",
        text=comment,
        metadata={
            "from": old_state,
            "to": destination,
            "previous_responsible": old_responsible,
            "responsible": app.responsible_id,
            "previous_due_at": old_due.isoformat(),
            "due_at": app.due_at.isoformat(),
            "leader_decision": leader_decision,
        },
    )
    notify(
        app,
        app.responsible,
        f"{app.folio}: {app.state_label}",
        f"transition:{app.pk}:{app.revision}",
    )
    audit(member, "transition", app, {"from": old_state, "to": destination})
    return app


@transaction.atomic
def reassign(app, member, responsible, reason):
    authorize(member, CONFIG_ROLES)
    scoped(member, app)
    scoped(member, responsible)
    if not reason.strip():
        raise ValidationError("Indica el motivo de reasignación.")
    if (
        not responsible.active
        or not responsible.user.is_active
        or responsible.role not in WRITE_ROLES
    ):
        raise ValidationError("Responsable inválido.")
    app = Application.objects.select_for_update().get(pk=app.pk)
    previous = app.responsible
    app.responsible = responsible
    app.revision += 1
    app.last_activity_at = timezone.now()
    app.save()
    Event.objects.create(
        application=app,
        actor=member.user,
        kind="reassignment",
        text=reason,
        metadata={"from": previous.pk, "to": responsible.pk},
    )
    notify(
        app,
        responsible,
        f"Se te asignó {app.folio}",
        f"assigned:{app.pk}:{app.revision}",
    )
    notify(
        app, previous, f"Se reasignó {app.folio}", f"unassigned:{app.pk}:{app.revision}"
    )
    audit(member, "reassignment", app)


@transaction.atomic
def pause(app, member, reason):
    authorize(member, CONFIG_ROLES)
    scoped(member, app)
    app = Application.objects.select_for_update().get(pk=app.pk)
    if app.closed_at:
        raise ValidationError("La solicitud ya terminó.")
    if app.pause_started:
        duration = timezone.now() - app.pause_started
        app.due_at += duration
        app.pause_started = None
        app.pause_reason = ""
        kind = "resume"
    else:
        if not reason.strip():
            raise ValidationError("La pausa requiere motivo.")
        app.pause_started = timezone.now()
        app.pause_reason = reason
        kind = "pause"
    app.revision += 1
    app.last_activity_at = timezone.now()
    app.save()
    Event.objects.create(
        application=app,
        actor=member.user,
        kind=kind,
        text=reason or "SLA reanudado",
        metadata={"due_at": app.due_at.isoformat()},
    )
    audit(member, kind, app)
