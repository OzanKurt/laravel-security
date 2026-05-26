<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Models\Lookups\LogLevel;

class AuditLog extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_audit_log';

    protected $fillable = [
        'uuid', 'correlation_id', 'kind_id', 'severity_id',
        'actor_type', 'actor_id', 'subject_type', 'subject_id',
        'ip', 'user_agent', 'url', 'description',
        'changes', 'meta', 'prev_hash', 'hmac',
    ];

    protected $casts = ['changes' => 'json', 'meta' => 'json'];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function kind()
    {
        return $this->belongsTo(AuditLogKind::class, 'kind_id');
    }

    public function severity()
    {
        return $this->belongsTo(LogLevel::class, 'severity_id');
    }
}
