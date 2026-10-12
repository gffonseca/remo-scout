<?php

namespace App\Models\Concerns;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

/** Registra criação, alteração e exclusão na trilha de auditoria (sem segredos). */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => AuditEvent::record('created', $m, $m->auditPayload($m->getAttributes())));
        static::updated(function (Model $m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $before = array_intersect_key($m->getOriginal(), $changes);
            AuditEvent::record('updated', $m, ['before' => $m->auditPayload($before), 'after' => $m->auditPayload($changes)]);
        });
        static::deleted(fn (Model $m) => AuditEvent::record('deleted', $m, null));
    }

    protected function auditPayload(array $attrs): array
    {
        foreach (['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'cpf'] as $secret) {
            unset($attrs[$secret]);
        }

        return $attrs;
    }
}
