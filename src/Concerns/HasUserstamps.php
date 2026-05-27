<?php

namespace OzanKurt\Shield\Concerns;

use Illuminate\Support\Facades\Auth;

trait HasUserstamps
{
    public static function bootHasUserstamps(): void
    {
        static::creating(function ($model) {
            $userId = Auth::id();
            if ($userId !== null) {
                if (empty($model->created_by_id)) {
                    $model->created_by_id = $userId;
                }
                if (empty($model->updated_by_id)) {
                    $model->updated_by_id = $userId;
                }
            }
        });

        static::updating(function ($model) {
            $userId = Auth::id();
            if ($userId !== null) {
                $model->updated_by_id = $userId;
            }
        });

        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                $userId = Auth::id();
                if ($userId !== null) {
                    // Set in-memory so the model reflects the new value
                    $model->deleted_by_id = $userId;
                    // Persist via query builder (skips model events to avoid cascade)
                    $model->newQuery()
                        ->withoutGlobalScopes()
                        ->where($model->getKeyName(), $model->getKey())
                        ->update(['deleted_by_id' => $userId]);
                }
            }
        });
    }
}
