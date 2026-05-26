<?php

namespace OzanKurt\Shield\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Tests\TestCase;

class HasUserstampsTest extends TestCase
{
    public function test_creating_fills_created_by_id_from_auth(): void
    {
        Auth::shouldReceive('id')->andReturn(42);

        $model = new class extends Model {
            use HasUserstamps;
            protected $table = 'test_userstamps';
            protected $guarded = [];
            public $timestamps = false;

            public function triggerCreating(): void
            {
                $this->fireModelEvent('creating');
            }
        };

        $model->triggerCreating();

        $this->assertSame(42, $model->created_by_id);
        $this->assertSame(42, $model->updated_by_id);
    }

    public function test_updating_fills_only_updated_by_id(): void
    {
        Auth::shouldReceive('id')->andReturn(7);

        $model = new class extends Model {
            use HasUserstamps;
            protected $table = 'test_userstamps';
            protected $guarded = [];
            public $timestamps = false;

            public function triggerUpdating(): void
            {
                $this->fireModelEvent('updating');
            }
        };
        $model->created_by_id = 5;

        $model->triggerUpdating();

        $this->assertSame(5, $model->created_by_id);
        $this->assertSame(7, $model->updated_by_id);
    }

    public function test_handles_unauthenticated_context(): void
    {
        Auth::shouldReceive('id')->andReturn(null);

        $model = new class extends Model {
            use HasUserstamps;
            protected $table = 'test_userstamps';
            protected $guarded = [];
            public $timestamps = false;

            public function triggerCreating(): void
            {
                $this->fireModelEvent('creating');
            }
        };

        $model->triggerCreating();

        $this->assertNull($model->created_by_id);
    }
}
