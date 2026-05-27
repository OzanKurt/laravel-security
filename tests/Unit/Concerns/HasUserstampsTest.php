<?php

namespace OzanKurt\Shield\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use OzanKurt\Shield\Concerns\HasUserstamps;
use OzanKurt\Shield\Tests\TestCase;

class HasUserstampsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a dedicated table for the deleting tests that need real DB ops.
        Schema::connection('testbench')->create('test_userstamps_soft', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->unsignedBigInteger('deleted_by_id')->nullable();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('testbench')->dropIfExists('test_userstamps_soft');

        parent::tearDown();
    }

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

    public function test_soft_deleting_fills_deleted_by_id(): void
    {
        Auth::shouldReceive('id')->andReturn(10);

        $model = new class extends Model {
            use SoftDeletes, HasUserstamps;
            protected $connection = 'testbench';
            protected $table = 'test_userstamps_soft';
            protected $guarded = [];
            public $timestamps = false;
        };

        $inserted = $model->newQuery()->insertGetId([
            'created_by_id' => 10,
            'updated_by_id' => 10,
            'deleted_by_id' => null,
        ]);
        $record = $model->newQuery()->find($inserted);

        $record->delete();

        $this->assertSame(10, $record->deleted_by_id);
        // Confirm it is persisted in the DB
        $fresh = $model->newQuery()->withTrashed()->find($inserted);
        $this->assertSame(10, $fresh->deleted_by_id);
        $this->assertNotNull($fresh->deleted_at);
    }

    public function test_force_deleting_does_not_modify_deleted_by_id(): void
    {
        Auth::shouldReceive('id')->andReturn(20);

        $model = new class extends Model {
            use SoftDeletes, HasUserstamps;
            protected $connection = 'testbench';
            protected $table = 'test_userstamps_soft';
            protected $guarded = [];
            public $timestamps = false;
        };

        $inserted = $model->newQuery()->insertGetId([
            'created_by_id' => 20,
            'updated_by_id' => 20,
            'deleted_by_id' => null,
        ]);
        $record = $model->newQuery()->find($inserted);

        $record->forceDelete();

        // Record should be gone from DB entirely
        $fresh = $model->newQuery()->withTrashed()->find($inserted);
        $this->assertNull($fresh);
    }

    public function test_soft_deleting_does_not_trigger_extra_updated_by_id_stamp(): void
    {
        // Simulate: created/updated by user A (id=1), then deleted as user B (id=2).
        // After delete, updated_by_id must still be 1 (no cascade through updating).
        //
        // We insert the row directly (raw query builder, no model events) so that
        // Auth::id() is never called during setup. Then we mock user B for the delete.
        Auth::shouldReceive('id')->andReturn(2); // user B throughout this test

        $model = new class extends Model {
            use SoftDeletes, HasUserstamps;
            protected $connection = 'testbench';
            protected $table = 'test_userstamps_soft';
            protected $guarded = [];
            public $timestamps = false;
        };

        // Insert via raw query so no Eloquent events fire and updated_by_id stays 1.
        \Illuminate\Support\Facades\DB::connection('testbench')
            ->table('test_userstamps_soft')
            ->insert([
                'created_by_id' => 1,
                'updated_by_id' => 1,
                'deleted_by_id' => null,
            ]);
        $inserted = \Illuminate\Support\Facades\DB::connection('testbench')
            ->table('test_userstamps_soft')
            ->orderByDesc('id')
            ->value('id');

        $record = $model->newQuery()->find($inserted);

        $record->delete();

        // deleted_by_id should be user B (2)
        $this->assertSame(2, $record->deleted_by_id);

        // updated_by_id must still be user A (1) — no cascade through updating event
        $fresh = $model->newQuery()->withTrashed()->find($inserted);
        $this->assertSame(1, $fresh->updated_by_id);
        $this->assertSame(2, $fresh->deleted_by_id);
    }
}
