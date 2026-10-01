import sqlite3
import zipfile
import uuid
from django.conf import settings
from django.utils import timezone
from .models import Backup
from .services import audit


def make_backup(congregation, member=None):
    folder = settings.DATA_DIR / "backups"
    folder.mkdir(mode=0o700, exist_ok=True)
    stem = timezone.now().strftime("%Y%m%d-%H%M%S") + "-" + uuid.uuid4().hex[:8]
    db_copy = folder / (stem + ".sqlite3")
    archive = folder / (stem + ".zip")
    source = sqlite3.connect(settings.DATABASES["default"]["NAME"])
    target = sqlite3.connect(db_copy)
    try:
        source.backup(target)
    finally:
        source.close()
        target.close()
    try:
        with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as output:
            output.write(db_copy, "integra.sqlite3")
            if settings.MEDIA_ROOT.exists():
                for file in settings.MEDIA_ROOT.rglob("*"):
                    if file.is_file():
                        output.write(
                            file,
                            "uploads/" + str(file.relative_to(settings.MEDIA_ROOT)),
                        )
        archive.chmod(0o600)
        backup = Backup.objects.create(
            congregation=congregation,
            filename=archive.name,
            size=archive.stat().st_size,
        )
        if member:
            audit(member, "backup.created", backup)
        return backup
    finally:
        db_copy.unlink(missing_ok=True)
