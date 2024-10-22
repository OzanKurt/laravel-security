<?php

namespace OzanKurt\Security\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuthLog extends Model
{
    use SoftDeletes;

    protected $fillable = ['email', 'is_successful', 'user_id', 'ip', 'user_agent', 'referrer', 'request_data', 'meta_data', 'is_notification_sent', 'notification_sent_at'];

    protected $casts = [
        'request_data' => 'json',
        'meta_data' => 'json',
        'notification_sent_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('security.database.connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('security.database.table_prefix').config('security.database.auth_log.table'));
        }

        parent::__construct($attributes);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('security.database.user.model'), 'user_id', 'id');
    }
}
