<?php

namespace OzanKurt\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Log extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'ip', 'level', 'middleware', 'url', 'referrer', 'request_data', 'user_agent'];

    protected $casts = [
        'deleted_at' => 'datetime',
        'request_data' => 'json',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('security.database.connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('security.database.table_prefix').config('security.database.log.table'));
        }

        parent::__construct($attributes);
    }

    public function user()
    {
        return $this->belongsTo(config('security.database.user.model'));
    }
}
