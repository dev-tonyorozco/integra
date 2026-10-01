import json
from django import forms
from django.contrib.auth.models import User
from django.contrib.auth.password_validation import validate_password
from django.core.exceptions import ValidationError
from .models import (
    Ministry,
    Workflow,
    Questionnaire,
    Opportunity,
    Interview,
    Membership,
    Congregation,
)
from .services import validate_workflow, validate_questions, WRITE_ROLES


class JsonWidget(forms.Textarea):
    def format_value(self, value):
        if value is None:
            return ""
        if isinstance(value, str):
            try:
                value = json.loads(value)
            except (ValueError, TypeError):
                return value
        return json.dumps(value, ensure_ascii=False, indent=2)


class MinistryForm(forms.ModelForm):
    class Meta:
        model = Ministry
        fields = [
            "name",
            "description",
            "leader_name",
            "contact",
            "requirements",
            "capacity",
            "active",
        ]


class WorkflowForm(forms.ModelForm):
    class Meta:
        model = Workflow
        fields = ["name", "version", "definition"]
        widgets = {"definition": JsonWidget(attrs={"rows": 18, "spellcheck": "false"})}

    def clean_definition(self):
        value = self.cleaned_data["definition"]
        validate_workflow(value)
        return value


class QuestionnaireForm(forms.ModelForm):
    class Meta:
        model = Questionnaire
        fields = ["name", "version", "instructions", "questions"]
        widgets = {"questions": JsonWidget(attrs={"rows": 18, "spellcheck": "false"})}

    def clean_questions(self):
        value = self.cleaned_data["questions"]
        validate_questions(value)
        return value


class OpportunityForm(forms.ModelForm):
    class Meta:
        model = Opportunity
        fields = [
            "title",
            "ministry",
            "description",
            "workflow",
            "questionnaire",
            "responsible",
            "closes_at",
            "active",
        ]
        widgets = {
            "closes_at": forms.DateTimeInput(
                attrs={"type": "datetime-local"}, format="%Y-%m-%dT%H:%M"
            )
        }

    def __init__(self, *args, member, **kwargs):
        super().__init__(*args, **kwargs)
        c = member.congregation
        self.fields["ministry"].queryset = Ministry.objects.filter(
            congregation=c, active=True
        )
        self.fields["workflow"].queryset = Workflow.objects.filter(
            congregation=c, published=True
        )
        self.fields["questionnaire"].queryset = Questionnaire.objects.filter(
            congregation=c, published=True, is_test=False
        )
        self.fields["responsible"].queryset = Membership.objects.filter(
            congregation=c, active=True, user__is_active=True, role__in=WRITE_ROLES
        )


class DynamicForm(forms.Form):
    def __init__(self, *args, questions, identity=False, **kwargs):
        super().__init__(*args, **kwargs)
        if identity:
            self.fields["name"] = forms.CharField(
                label="Nombre completo", max_length=160
            )
            self.fields["email"] = forms.EmailField(label="Correo electrónico")
            self.fields["phone"] = forms.RegexField(
                r"^\+?[0-9 ()-]{8,30}$", label="Teléfono"
            )
        for q in questions:
            opts = {
                "label": q["label"],
                "required": q.get("required", False),
                "help_text": q.get("help", ""),
            }
            kind = q.get("type", "text")
            if kind == "select":
                field = forms.ChoiceField(
                    choices=[("", "Selecciona…")] + [(x, x) for x in q["options"]],
                    **opts,
                )
            elif kind == "multiselect":
                field = forms.MultipleChoiceField(
                    choices=[(x, x) for x in q["options"]],
                    widget=forms.CheckboxSelectMultiple,
                    **opts,
                )
            elif kind == "boolean":
                field = forms.BooleanField(**opts)
            elif kind == "number":
                field = forms.IntegerField(
                    min_value=q.get("min", 0), max_value=q.get("max", 10000), **opts
                )
            elif kind == "email":
                field = forms.EmailField(**opts)
            elif kind == "date":
                field = forms.DateField(
                    widget=forms.DateInput(attrs={"type": "date"}), **opts
                )
            else:
                field = forms.CharField(
                    max_length=5000,
                    widget=forms.Textarea(attrs={"rows": 3})
                    if kind == "textarea"
                    else forms.TextInput,
                    **opts,
                )
            self.fields["q_" + q["id"]] = field
        if identity:
            self.fields["website"] = forms.CharField(
                required=False, widget=forms.HiddenInput
            )
            self.fields["consent"] = forms.BooleanField(
                label="He leído y acepto el aviso de privacidad para esta solicitud."
            )

    def answers(self):
        return {
            key[2:]: str(value) if hasattr(value, "isoformat") else value
            for key, value in self.cleaned_data.items()
            if key.startswith("q_")
        }


class InterviewForm(forms.ModelForm):
    class Meta:
        model = Interview
        fields = ["scheduled_at", "interviewer", "location", "status", "result"]
        widgets = {
            "scheduled_at": forms.DateTimeInput(
                attrs={"type": "datetime-local"}, format="%Y-%m-%dT%H:%M"
            )
        }


class UserForm(forms.Form):
    name = forms.CharField(label="Nombre", max_length=160)
    email = forms.EmailField(label="Correo")
    role = forms.ChoiceField(label="Rol", choices=Membership.ROLES)
    password = forms.CharField(
        label="Contraseña inicial (mínimo 12 caracteres)", widget=forms.PasswordInput
    )

    def clean_password(self):
        password = self.cleaned_data["password"]
        validate_password(password)
        return password

    def clean_email(self):
        value = self.cleaned_data["email"].lower()
        if User.objects.filter(username=value).exists():
            raise ValidationError(
                "Ya existe esta cuenta. Administra sus accesos con el comando grant_access."
            )
        return value


class CongregationForm(forms.ModelForm):
    class Meta:
        model = Congregation
        fields = ["name", "slug", "privacy_notice"]
