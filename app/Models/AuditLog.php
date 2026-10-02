<?php

namespace App\Models;

class AuditLog extends ScopedModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Historial inmutable'));
        static::deleting(fn () => abort(403, 'Historial inmutable'));
    }
}
