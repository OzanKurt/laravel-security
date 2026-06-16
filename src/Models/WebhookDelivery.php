<?php

namespace OzanKurt\Shield\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Concerns\HasUuid;

/**
 * One row per outbound webhook/heartbeat/ping delivery attempt to the
 * Central app. Lifecycle:
 *
 *   dispatch → pending  (job picked up, about to POST)
 *   pending → success   (2xx response)
 *   pending → failure   (4xx, permanent, no retry)
 *   pending → failure   (5xx or connection error, retried by queue)
 *   pending → exhausted (all retries failed; job will not retry)
 *   pending → skipped   (no license / no URL / circuit open)
 *
 * Updates are append-only to a single row per attempt, the queue's
 * retry counter creates a new row for each retry, so the row trail
 * tells the full story of one logical event's delivery.
 */
class WebhookDelivery extends Model
{
    use HasUuid, HasUserstamps, SoftDeletes;

    protected $table = 'ls_webhook_deliveries';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_EXHAUSTED = 'exhausted';

    protected $fillable = [
        'uuid', 'correlation_id',
        'operation', 'target_url', 'payload_hash', 'payload_bytes', 'batch_size',
        'status', 'reason', 'http_status', 'response_excerpt',
        'attempt_number', 'max_attempts',
        'dispatched_at', 'completed_at', 'duration_ms',
        'audit_log_id',
    ];

    protected $casts = [
        'payload_bytes' => 'integer',
        'batch_size' => 'integer',
        'http_status' => 'integer',
        'attempt_number' => 'integer',
        'max_attempts' => 'integer',
        'duration_ms' => 'integer',
        'audit_log_id' => 'integer',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('shield.database.connection'));
        }
        parent::__construct($attributes);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_SKIPPED,
            self::STATUS_EXHAUSTED,
        ], true);
    }

    /**
     * Mark as completed with the outcome from a DeliveryResult. Sets
     * completed_at and duration_ms in one update so the row reflects
     * the final state atomically.
     */
    public function markCompleted(string $status, ?int $httpStatus, ?string $reason, ?string $excerpt): void
    {
        $completedAt = now();
        $durationMs = null;
        if ($this->dispatched_at) {
            // Carbon 2.62+ / 3.x: diffInMilliseconds returns a SIGNED float.
            // Calling now->diffInMilliseconds(past) returns NEGATIVE (since
            // past is earlier than now). The earlier code did max(0, negative)
            // → 0, so every row had duration_ms=0. Pass the absolute flag so
            // the result is always positive regardless of Carbon version.
            $durationMs = (int) abs($this->dispatched_at->diffInMilliseconds($completedAt));
        }

        $this->update([
            'status' => $status,
            'http_status' => $httpStatus ?? 0,
            'reason' => $reason,
            'response_excerpt' => $excerpt,
            'completed_at' => $completedAt,
            'duration_ms' => $durationMs,
        ]);
    }
}
