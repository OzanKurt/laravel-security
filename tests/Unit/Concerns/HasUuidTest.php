<?php

namespace OzanKurt\Shield\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use OzanKurt\Shield\Concerns\HasUuid;
use OzanKurt\Shield\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class HasUuidTest extends TestCase
{
    public function test_creating_a_model_auto_generates_a_uuid7(): void
    {
        $model = new class extends Model {
            use HasUuid;

            protected $table = 'test_uuid_models';
            protected $guarded = [];
            public $timestamps = false;
            public $incrementing = false;

            public function triggerCreating(): void
            {
                $this->fireModelEvent('creating');
            }
        };

        $model->triggerCreating();

        $this->assertNotNull($model->uuid);
        $this->assertEquals(36, strlen($model->uuid));

        $version = Uuid::fromString($model->uuid)->getVersion();
        $this->assertSame(7, $version);
    }

    public function test_uuid_is_not_overwritten_if_already_set(): void
    {
        $existing = Uuid::uuid7()->toString();

        $model = new class extends Model {
            use HasUuid;
            protected $table = 'test_uuid_models';
            protected $guarded = [];
            public $timestamps = false;
            public $incrementing = false;

            public function triggerCreating(): void
            {
                $this->fireModelEvent('creating');
            }
        };
        $model->uuid = $existing;

        $model->triggerCreating();

        $this->assertSame($existing, $model->uuid);
    }
}
