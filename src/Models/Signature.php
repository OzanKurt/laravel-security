<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;

class Signature extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_signatures';

    protected $fillable = [
        'uuid', 'correlation_id', 'source', 'source_ref',
        'name', 'description', 'category_id', 'kind',
        'pattern', 'severity_id', 'version', 'is_enabled', 'meta',
    ];

    protected $casts = [
        'meta' => 'json',
        'is_enabled' => 'boolean',
        'version' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function category()
    {
        return $this->belongsTo(SignatureCategory::class, 'category_id');
    }

    public function severity()
    {
        return $this->belongsTo(LogLevel::class, 'severity_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
