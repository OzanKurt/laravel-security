<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\ScannerStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTrigger;

class ScannerRun extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_scanner_runs';

    protected $fillable = [
        'uuid', 'correlation_id', 'trigger_id', 'status_id',
        'targets', 'backends', 'started_at', 'finished_at',
        'files_scanned', 'findings_count', 'findings_critical_count', 'error_message',
    ];

    protected $casts = [
        'targets' => 'json',
        'backends' => 'json',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'files_scanned' => 'integer',
        'findings_count' => 'integer',
        'findings_critical_count' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function trigger()
    {
        return $this->belongsTo(ScannerTrigger::class, 'trigger_id');
    }

    public function status()
    {
        return $this->belongsTo(ScannerStatus::class, 'status_id');
    }

    public function findings()
    {
        return $this->hasMany(ScannerFinding::class, 'scanner_run_id');
    }
}
