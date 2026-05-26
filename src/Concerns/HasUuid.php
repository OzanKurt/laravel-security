<?php

namespace OzanKurt\Shield\Concerns;

use Ramsey\Uuid\Uuid;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Uuid::uuid7()->toString();
            }
        });
    }
}
