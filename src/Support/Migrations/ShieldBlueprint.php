<?php

namespace OzanKurt\Shield\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;

class ShieldBlueprint
{
    public static function applyStandard(Blueprint $table): void
    {
        $table->uuid('uuid')->unique();
        $table->timestamps();
        $table->softDeletes();
        $table->unsignedBigInteger('created_by_id')->nullable()->index();
        $table->unsignedBigInteger('updated_by_id')->nullable()->index();
        $table->unsignedBigInteger('deleted_by_id')->nullable()->index();
    }

    public static function applyCorrelationId(Blueprint $table): void
    {
        $table->uuid('correlation_id')->nullable()->index();
    }
}
