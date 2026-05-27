<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;

class WafRule extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_waf_rules';

    protected $fillable = [
        'uuid', 'correlation_id', 'source', 'source_ref',
        'name', 'description', 'category_id', 'kind_id',
        'target_id', 'pattern', 'action_id', 'severity_id',
        'score', 'is_enabled', 'meta', 'version',
    ];

    protected $casts = [
        'meta' => 'json',
        'is_enabled' => 'boolean',
        'score' => 'integer',
        'version' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) { $this->setConnection(config('shield.database.connection')); }
        parent::__construct($attributes);
    }

    public function category() { return $this->belongsTo(WafRuleCategory::class, 'category_id'); }
    public function kind() { return $this->belongsTo(WafRuleKind::class, 'kind_id'); }
    public function target() { return $this->belongsTo(WafRuleTarget::class, 'target_id'); }
    public function action() { return $this->belongsTo(WafRuleAction::class, 'action_id'); }
    public function severity() { return $this->belongsTo(LogLevel::class, 'severity_id'); }

    public function scopeEnabled($query) { return $query->where('is_enabled', true); }
}
