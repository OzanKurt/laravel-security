<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Enums\IpEntryType;

class Ip extends Model
{
    use SoftDeletes;

    protected $fillable = ['ip', 'log_id', 'entry_type', 'request_count'];

    protected $casts = [
        'entry_type' => IpEntryType::class,
        'deleted_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('shield.database.table_prefix').config('shield.database.ip.table'));
        }

        // add a scope to the model
//        $this->addGlobalScope('test', function ($builder) {
//            $builder->where('id', 0);
//        });

        parent::__construct($attributes);
    }

    public function log()
    {
        return $this->belongsTo(config('shield.database.log.model'));
    }

    public function logs()
    {
        return $this->hasMany(config('shield.database.log.model'), 'ip', 'ip');
    }
}
