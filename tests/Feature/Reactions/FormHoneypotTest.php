<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use OzanKurt\Shield\Http\Middleware\ProtectAgainstSpam;
use OzanKurt\Shield\Services\Scoring\SuspicionScorer;
use OzanKurt\Shield\Tests\TestCase;

class FormHoneypotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['shield.honeypot.form.enabled' => true]);
        config(['shield.honeypot.form.response' => 'ok']);
        config(['shield.scoring.enabled' => true]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:Lf+1x2r3feOZ2hfF6Ksn6JSwbR4yGJ3vYri6EGr/EuA=');
    }

    public function testCleanSubmissionPassesThrough()
    {
        $request = Request::create('/contact', 'POST', [
            'shield_hp' => '',
            'shield_hp_time' => Crypt::encryptString((string) now()->subSeconds(5)->timestamp),
        ]);

        $result = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');
        $this->assertSame('next', $result);
    }

    public function testFilledHoneypotIsDiscardedAndScored()
    {
        $scorer = $this->spy(SuspicionScorer::class);
        $this->app->instance(SuspicionScorer::class, $scorer);

        $request = Request::create('/contact', 'POST', [
            'shield_hp' => 'i-am-a-bot',
            'shield_hp_time' => Crypt::encryptString((string) now()->subSeconds(5)->timestamp),
        ]);
        $request->server->set('REMOTE_ADDR', '203.0.113.70');

        $response = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');

        $this->assertNotSame('next', $response);
        $this->assertSame(200, $response->getStatusCode());
        $scorer->shouldHaveReceived('bump')->once();
    }

    public function testTooFastSubmissionIsDiscarded()
    {
        $request = Request::create('/contact', 'POST', [
            'shield_hp' => '',
            'shield_hp_time' => Crypt::encryptString((string) now()->timestamp), // 0s elapsed
        ]);
        $request->server->set('REMOTE_ADDR', '203.0.113.71');

        $response = (new ProtectAgainstSpam())->handle($request, fn ($r) => 'next');
        $this->assertNotSame('next', $response);
    }
}
