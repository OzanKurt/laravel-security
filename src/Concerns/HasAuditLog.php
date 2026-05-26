<?php

namespace OzanKurt\Shield\Concerns;

use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Audit\AuditLogger;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

/**
 * Opt-in Eloquent trait that emits audit log entries on created/updated/deleted.
 *
 * Usage:
 *   class User extends Model
 *   {
 *       use HasAuditLog;
 *   }
 *
 * Overridable hooks (define on the model to customise behaviour):
 *   - auditLogChanges(): array   — what goes in the `changes` column (default: getDirty())
 *   - auditLogMeta(): array      — additional metadata stored in `meta`
 *   - auditLogShouldLog(string $event): bool — return false to suppress specific events
 */
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(function ($model) {
            $model->recordAuditLog('created');
        });

        static::updated(function ($model) {
            $model->recordAuditLog('updated');
        });

        static::deleted(function ($model) {
            $model->recordAuditLog('deleted');
        });
    }

    protected function recordAuditLog(string $event): void
    {
        if (method_exists($this, 'auditLogShouldLog') && ! $this->auditLogShouldLog($event)) {
            return;
        }

        $kindName = 'model.' . strtolower(class_basename($this)) . '.' . $event;

        $this->ensureAuditLogKindExists($kindName);

        $changes = method_exists($this, 'auditLogChanges')
            ? $this->auditLogChanges()
            : $this->getDirty();

        $meta = method_exists($this, 'auditLogMeta')
            ? $this->auditLogMeta()
            : [];

        app(AuditLogger::class)->log(
            $kindName,
            class_basename($this) . ' ' . $event . ' (id=' . $this->getKey() . ')',
            [
                'subject_type' => get_class($this),
                'subject_id'   => (string) $this->getKey(),
                'changes'      => empty($changes) ? null : $changes,
                'meta'         => empty($meta) ? null : $meta,
                'severity'     => 'low',
            ]
        );
    }

    /**
     * Ensures the audit_log_kind row exists for the given kind name.
     * Creates it on-the-fly so arbitrary models don't blow up with
     * "Unknown audit kind" exceptions.
     */
    protected function ensureAuditLogKindExists(string $kindName): void
    {
        /** @var LookupResolver $resolver */
        $resolver = app(LookupResolver::class);

        if ($resolver->id(AuditLogKind::class, $kindName) !== null) {
            return;
        }

        // Create the kind row, then flush the resolver cache so the next
        // call to AuditLogger picks up the new id.
        $label = ucwords(str_replace(['.', '_'], ' ', $kindName));

        AuditLogKind::firstOrCreate(
            ['name' => $kindName],
            ['label' => $label, 'sort_order' => 0]
        );

        $resolver->flush(AuditLogKind::class);
    }
}
