<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Support\Facades\Http;
use OzanKurt\Shield\Models\Acl;
use OzanKurt\Shield\Models\Lookups\AclAction;
use OzanKurt\Shield\Models\Lookups\AclKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Services\Reactions\AbuseIpDbReportReaction;
use OzanKurt\Shield\Tests\TestCase;

class AbuseIpDbReportReactionTest extends TestCase
{
    private function block(string $ip, array $meta = []): Acl
    {
        $lookups = app(LookupResolver::class);

        return Acl::create([
            'kind_id' => $lookups->id(AclKind::class, 'ip'),
            'action_id' => $lookups->id(AclAction::class, 'block'),
            'value' => $ip,
            'source' => 'honeypot',
            'reason' => 'Hit honeypot path: /.env',
            'meta' => $meta,
        ]);
    }

    public function testBanReportsAndRecordsTimestamp()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        Http::fake(['*/report' => Http::response(['data' => ['abuseConfidenceScore' => 100]], 200)]);

        $acl = $this->block('203.0.113.20');
        app(AbuseIpDbReportReaction::class)->ban($acl->fresh());

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/v2/report')
            && $r['ip'] === '203.0.113.20');
        $this->assertNotNull($acl->fresh()->meta['reactions']['abuseipdb']['reported_at']);
    }

    public function testAlreadyReportedDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);

        $acl = $this->block('203.0.113.20', ['reactions' => ['abuseipdb' => ['reported_at' => '2026-01-01T00:00:00+00:00']]]);
        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($acl));
    }

    public function testPrivateIpDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($this->block('192.168.1.5')));
    }

    public function testStaleBlockDoesNotApply()
    {
        config(['shield.reactions.abuseipdb_report.enabled' => true]);
        config(['shield.reactions.abuseipdb_report.api_key' => 'k']);
        config(['shield.reactions.abuseipdb_report.max_age_days' => 30]);

        $acl = $this->block('203.0.113.21');
        $acl->forceFill(['created_at' => now()->subDays(45)])->save();

        $this->assertFalse(app(AbuseIpDbReportReaction::class)->appliesTo($acl->fresh()));
    }
}
