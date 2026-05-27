<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\ActionKind;

class LiveTrafficRecord extends Model
{
    use HasUuid;

    protected $table = 'ls_live_traffic';

    protected $fillable = [
        'uuid', 'correlation_id', 'ip', 'country_code', 'asn', 'user_id',
        'method', 'url', 'status_code', 'response_time_ms',
        'user_agent', 'referrer', 'bot_identity', 'action_taken_id',
        'matched_rule_id', 'fingerprint_hash',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function actionTaken()
    {
        return $this->belongsTo(ActionKind::class, 'action_taken_id');
    }

    public function rule()
    {
        return $this->belongsTo(WafRule::class, 'matched_rule_id');
    }
}
