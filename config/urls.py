from django.urls import path
from django.contrib.auth import views as auth
from core import views as v

urlpatterns = [
    path("", v.dashboard, name="dashboard"),
    path("health/", v.health, name="health"),
    path("login/", v.signin, name="login"),
    path("logout/", auth.LogoutView.as_view(), name="logout"),
    path(
        "password/change/",
        auth.PasswordChangeView.as_view(
            template_name="core/form.html", success_url="/"
        ),
        name="password_change",
    ),
    path(
        "password/reset/",
        auth.PasswordResetView.as_view(
            template_name="registration/password_reset_form.html",
            email_template_name="registration/password_reset_email.txt",
            subject_template_name="registration/password_reset_subject.txt",
        ),
        name="password_reset",
    ),
    path(
        "password/reset/sent/",
        auth.PasswordResetDoneView.as_view(
            template_name="registration/password_reset_done.html"
        ),
        name="password_reset_done",
    ),
    path(
        "password/reset/<uidb64>/<token>/",
        auth.PasswordResetConfirmView.as_view(
            template_name="registration/password_reset_confirm.html"
        ),
        name="password_reset_confirm",
    ),
    path(
        "password/reset/complete/",
        auth.PasswordResetCompleteView.as_view(
            template_name="registration/password_reset_complete.html"
        ),
        name="password_reset_complete",
    ),
    path("switch/", v.switch, name="switch"),
    path("congregations/new/", v.congregation_create, name="congregation_create"),
    path("catalog/<str:kind>/", v.catalog, name="catalog"),
    path("catalog/<str:kind>/new/", v.edit_catalog, name="catalog_new"),
    path("catalog/<str:kind>/<int:pk>/", v.edit_catalog, name="catalog_edit"),
    path(
        "catalog/<str:kind>/<int:pk>/preview/",
        v.preview_catalog,
        name="catalog_preview",
    ),
    path("opportunities/<int:pk>/qr/", v.opportunity_qr, name="opportunity_qr"),
    path("catalog/<str:kind>/<int:pk>/publish/", v.publish, name="publish"),
    path("applications/", v.applications, name="applications"),
    path("applications/<int:pk>/", v.application_detail, name="application"),
    path("applications/<int:pk>/pdf/", v.pdf, name="pdf"),
    path("applications/<int:pk>/interview/", v.interview, name="interview"),
    path(
        "applications/<int:pk>/interview/<int:interview_pk>/",
        v.interview,
        name="interview_edit",
    ),
    path("applications/<int:pk>/upload/", v.upload, name="upload"),
    path("attachments/<int:pk>/", v.download, name="download"),
    path("people/", v.people, name="people"),
    path("users/", v.users, name="users"),
    path("users/<int:pk>/toggle/", v.user_toggle, name="user_toggle"),
    path("notifications/", v.notifications, name="notifications"),
    path("audit/", v.audit_log, name="audit"),
    path("reports/", v.reports, name="reports"),
    path("backups/", v.backups, name="backups"),
    path("serve/<slug:slug>/", v.public_home, name="public_home"),
    path("apply/<slug:slug>/", v.public_apply, name="public_apply"),
    path("receipt/", v.receipt, name="receipt"),
    path("respond/<uuid:token>/", v.public_task, name="public_task"),
]
