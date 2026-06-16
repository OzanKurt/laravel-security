<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\IntegrityChangeType;
use OzanKurt\Shield\Models\Lookups\IntegrityComparisonBasis;
use OzanKurt\Shield\Models\Lookups\LogLevel;

class IntegrityChange extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_integrity_changes';

    protected $fillable = [
        'uuid', 'correlation_id', 'integrity_run_id', 'change_type_id', 'compared_to_id',
        'severity_id', 'path', 'old_hash', 'new_hash', 'size_bytes', 'file_mtime', 'symlink_target',
    ];

    protected $casts = [
        'file_mtime' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function run()
    {
        return $this->belongsTo(IntegrityRun::class, 'integrity_run_id');
    }

    public function changeType()
    {
        return $this->belongsTo(IntegrityChangeType::class, 'change_type_id');
    }

    public function comparedTo()
    {
        return $this->belongsTo(IntegrityComparisonBasis::class, 'compared_to_id');
    }

    public function severity()
    {
        return $this->belongsTo(LogLevel::class, 'severity_id');
    }
}
