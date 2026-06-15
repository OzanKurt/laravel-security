<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;

class IntegrityBaseline extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_integrity_baselines';

    protected $fillable = [
        'uuid', 'disk', 'scope_fingerprint', 'root_hash', 'artifact_path',
        'algo', 'files_total', 'signed', 'blessed_at',
    ];

    protected $casts = [
        'files_total' => 'integer',
        'signed' => 'boolean',
        'blessed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }
}
