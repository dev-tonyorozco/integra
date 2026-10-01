from .models import Membership, Notification


def navigation(request):
    if not request.user.is_authenticated:
        return {}
    memberships = Membership.objects.filter(
        user=request.user, active=True, congregation__active=True
    ).select_related("congregation")
    member = (
        memberships.filter(congregation_id=request.session.get("congregation")).first()
        or memberships.first()
    )
    return {
        "member": member,
        "memberships": memberships,
        "unread": Notification.objects.filter(
            recipient=request.user,
            read_at__isnull=True,
            congregation=member.congregation,
        ).count()
        if member
        else 0,
        "can_write": bool(
            member and member.role in {"admin", "coordinator", "advisor"}
        ),
        "can_config": bool(member and member.role in {"admin", "coordinator"}),
    }
