<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\ScannerBackend;
use OzanKurt\Shield\Models\Lookups\ScannerFindingStatus;
use OzanKurt\Shield\Models\Lookups\ScannerTarget;

class ScannerFinding extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_scanner_findings';

    protected $fillable = [
        'uuid', 'correlation_id', 'scanner_run_id', 'target_id', 'backend_id',
        'signature_id', 'signature_ref', 'severity_id', 'status_id',
        'file_path', 'file_hash', 'line_number', 'matched_content',
        'notes', 'quarantine_path',
    ];

    protected $casts = [
        'line_number' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function scannerRun()
    {
        return $this->belongsTo(ScannerRun::class, 'scanner_run_id');
    }

    public function target()
    {
        return $this->belongsTo(ScannerTarget::class, 'target_id');
    }

    public function backend()
    {
        return $this->belongsTo(ScannerBackend::class, 'backend_id');
    }

    public function signature()
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }

    public function severity()
    {
        return $this->belongsTo(LogLevel::class, 'severity_id');
    }

    public function status()
    {
        return $this->belongsTo(ScannerFindingStatus::class, 'status_id');
    }
}
