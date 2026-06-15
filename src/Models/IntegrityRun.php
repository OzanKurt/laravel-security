<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\IntegrityStatus;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;

class IntegrityRun extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_integrity_runs';

    protected $fillable = [
        'uuid', 'correlation_id', 'status_id', 'trigger_id', 'severity_id',
        'disk', 'scope_fingerprint', 'baseline_root_hash', 'current_root_hash',
        'files_total', 'files_hashed', 'files_size_only', 'files_unreadable', 'files_excluded',
        'count_new', 'count_modified', 'count_deleted', 'count_scope_changed', 'count_vs_known_good',
        'started_at', 'finished_at', 'duration_ms', 'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'files_total' => 'integer',
        'files_hashed' => 'integer',
        'files_size_only' => 'integer',
        'files_unreadable' => 'integer',
        'files_excluded' => 'integer',
        'count_new' => 'integer',
        'count_modified' => 'integer',
        'count_deleted' => 'integer',
        'count_scope_changed' => 'integer',
        'count_vs_known_good' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function status()
    {
        return $this->belongsTo(IntegrityStatus::class, 'status_id');
    }

    public function trigger()
    {
        return $this->belongsTo(ScannerTrigger::class, 'trigger_id');
    }

    public function severity()
    {
        return $this->belongsTo(LogLevel::class, 'severity_id');
    }

    public function changes()
    {
        return $this->hasMany(IntegrityChange::class, 'integrity_run_id');
    }
}
