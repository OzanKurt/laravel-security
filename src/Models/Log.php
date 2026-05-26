<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Log extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'ip', 'level', 'middleware', 'url', 'referrer', 'request_data', 'meta_data', 'user_agent'];

    protected $casts = [
        'request_data' => 'json',
        'meta_data' => 'json',
        'deleted_at' => 'datetime',
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
