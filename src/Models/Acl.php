<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;

class Acl extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_acl';

    protected $fillable = [
        'uuid', 'correlation_id', 'kind_id', 'value', 'action_id',
        'source', 'reason', 'log_id', 'hit_count', 'expires_at', 'meta',
    ];

    protected $casts = [
        'meta' => 'json',
        'expires_at' => 'datetime',
        'hit_count' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function kind()
    {
        return $this->belongsTo(AclKind::class, 'kind_id');
    }

    public function action()
    {
        return $this->belongsTo(AclAction::class, 'action_id');
    }

    public function log()
    {
        return $this->belongsTo(config('shield.database.log.model'));
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeOfAction($query, string $actionName)
    {
        $actionId = app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class)
            ->id(AclAction::class, $actionName);

        return $query->where('action_id', $actionId);
    }

    public function scopeOfKind($query, string $kindName)
    {
        $kindId = app(\OzanKurt\Shield\Services\Lookups\LookupResolver::class)
            ->id(AclKind::class, $kindName);

        return $query->where('kind_id', $kindId);
    }
}
