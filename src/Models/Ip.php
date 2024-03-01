<?php

namespace OzanKurt\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ip extends Model
{
    use SoftDeletes;

    protected $fillable = ['ip', 'log_id', 'is_blocked'];

    protected $casts = [
        'deleted_at' => 'datetime',
        'is_blocked' => 'bool',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('security.database.connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('security.database.tables.firewall_logs'));
        }

        parent::__construct($attributes);
    }

    public function log()
    {
        return $this->belongsTo(config('security.database.log.model'));
    }

    public function logs()
    {
        return $this->hasMany(config('security.database.log.model'), 'ip', 'ip');
    }

    public function scopeBlocked($query, $ip = null)
    {
        $q = $query->where('is_blocked', 1);

        if ($ip) {
            $q = $query->where('ip', $ip);
        }

        return $q;
    }
}
