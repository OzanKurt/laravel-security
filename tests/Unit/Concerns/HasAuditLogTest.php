<?php

namespace OzanKurt\Shield\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use OzanKurt\Shield\Concerns\HasAuditLog;
use OzanKurt\Shield\Models\AuditLog;
use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Tests\TestCase;

// Named stub model, avoids the anonymous-class basename weirdness in tests
class AuditableWidget extends Model
{
    use HasAuditLog;

    protected $connection = 'testbench';
    protected $table = 'test_audit_models';
    protected $guarded = [];
}

// Stub model that suppresses the "updated" event
class AuditableWidgetNoUpdates extends Model
{
    use HasAuditLog;

    protected $connection = 'testbench';
    protected $table = 'test_audit_models';
    protected $guarded = [];

    public function auditLogShouldLog(string $event): bool
    {
        return $event !== 'updated';
    }
}

class HasAuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection('testbench')
            ->getSchemaBuilder()
            ->create('test_audit_models', function ($table) {
                $table->increments('id');
                $table->string('name')->nullable();
                $table->timestamps();
            });
    }

    // -------------------------------------------------------------------------
    // created event
    // -------------------------------------------------------------------------

    public function test_creates_audit_log_entry_on_model_created(): void
    {
        AuditableWidget::create(['name' => 'Alice']);

        $kindName = 'model.auditablewidget.created';
        $kind = AuditLogKind::where('name', $kindName)->first();

        $this->assertNotNull($kind, "Kind {$kindName} should be auto-created");
        $this->assertGreaterThan(
            0,
            AuditLog::where('kind_id', $kind->id)->count(),
            'Expected at least one AuditLog entry for ' . $kindName
        );
    }

    // -------------------------------------------------------------------------
    // updated event
    // -------------------------------------------------------------------------

    public function test_creates_audit_log_entry_on_model_updated(): void
    {
        $instance = AuditableWidget::create(['name' => 'Bob']);
        $instance->update(['name' => 'Robert']);

        $kindName = 'model.auditablewidget.updated';
        $kind = AuditLogKind::where('name', $kindName)->first();
        $this->assertNotNull($kind, "Kind {$kindName} should be auto-created");

        $this->assertGreaterThan(
            0,
            AuditLog::where('kind_id', $kind->id)->count(),
            'Expected at least one AuditLog entry for ' . $kindName
        );
    }

    // -------------------------------------------------------------------------
    // deleted event
    // -------------------------------------------------------------------------

    public function test_creates_audit_log_entry_on_model_deleted(): void
    {
        $instance = AuditableWidget::create(['name' => 'Charlie']);
        $instance->delete();

        $kindName = 'model.auditablewidget.deleted';
        $kind = AuditLogKind::where('name', $kindName)->first();
        $this->assertNotNull($kind, "Kind {$kindName} should be auto-created");

        $this->assertGreaterThan(
            0,
            AuditLog::where('kind_id', $kind->id)->count(),
            'Expected at least one AuditLog entry for ' . $kindName
        );
    }

    // -------------------------------------------------------------------------
    // subject_type and subject_id populated correctly
    // -------------------------------------------------------------------------

    public function test_subject_type_and_id_are_populated(): void
    {
        $instance = AuditableWidget::create(['name' => 'Diana']);

        $kind = AuditLogKind::where('name', 'model.auditablewidget.created')->first();
        $entry = AuditLog::where('kind_id', $kind->id)
            ->where('subject_type', AuditableWidget::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'Audit entry should have correct subject_type');
        $this->assertEquals((string) $instance->id, $entry->subject_id);
    }

    // -------------------------------------------------------------------------
    // auditLogShouldLog suppression
    // -------------------------------------------------------------------------

    public function test_respects_auditLogShouldLog_suppression(): void
    {
        $instance = AuditableWidgetNoUpdates::create(['name' => 'Dave']);
        $beforeCount = AuditLog::count();
        $instance->update(['name' => 'David']);

        $this->assertEquals($beforeCount, AuditLog::count(), 'Update audit entry should be suppressed');
    }

    // -------------------------------------------------------------------------
    // auto kind creation is idempotent
    // -------------------------------------------------------------------------

    public function test_auto_created_kind_is_idempotent(): void
    {
        AuditableWidget::create(['name' => 'Eve']);
        AuditableWidget::create(['name' => 'Eve2']);

        $this->assertEquals(
            1,
            AuditLogKind::where('name', 'model.auditablewidget.created')->count(),
            'Kind row should be created exactly once'
        );
    }

    // -------------------------------------------------------------------------
    // changes column contains dirty attributes on update
    // -------------------------------------------------------------------------

    public function test_changes_contains_dirty_attributes_on_update(): void
    {
        $instance = AuditableWidget::create(['name' => 'Frank']);
        $instance->update(['name' => 'Francis']);

        $kind = AuditLogKind::where('name', 'model.auditablewidget.updated')->first();
        $this->assertNotNull($kind);

        $entry = AuditLog::where('kind_id', $kind->id)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertNotNull($entry->changes);
        $this->assertArrayHasKey('name', $entry->changes);
        $this->assertEquals('Francis', $entry->changes['name']);
    }
}
