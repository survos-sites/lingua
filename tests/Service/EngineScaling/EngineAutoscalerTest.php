<?php

declare(strict_types=1);

namespace App\Tests\Service\EngineScaling;

use App\Service\EngineScaling\EngineAutoscaler;
use App\Service\EngineScaling\EngineNotReadyException;
use App\Service\EngineScaling\EngineScalerInterface;
use App\Service\EngineScaling\EngineScalingException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FakeScaler implements EngineScalerInterface
{
    /** @var list<int> */
    public array $calls = [];
    public bool $fail = false;

    public function scaleTo(int $replicas): void
    {
        if ($this->fail) {
            throw new EngineScalingException('boom');
        }
        $this->calls[] = $replicas;
    }
}

final class EngineAutoscalerTest extends TestCase
{
    private FakeScaler $scaler;
    private MockClock $clock;
    /** @var list<int> HTTP status codes the health URL returns, in order; last repeats */
    private array $health = [200];
    private int $healthCalls = 0;

    private function make(bool $enabled = true, int $idleMinutes = 10, int $timeout = 60, int $max = 1): EngineAutoscaler
    {
        $this->scaler = new FakeScaler();
        $this->clock = new MockClock('2026-11-01 12:00:00');
        $http = new MockHttpClient(function (): MockResponse {
            $code = $this->health[min($this->healthCalls++, \count($this->health) - 1)];

            return new MockResponse('[]', ['http_code' => $code]);
        });

        return new EngineAutoscaler(
            $this->scaler,
            $http,
            new ArrayAdapter(),
            $this->clock,
            new NullLogger(),
            $enabled,
            'https://libre.test/languages',
            ['libre'],
            $idleMinutes,
            $timeout,
            $max,
            5,
            function (int $s): void { $this->clock->sleep($s); },
        );
    }

    public function testManagesOnlyScaledEnginesWhenEnabled(): void
    {
        self::assertTrue($this->make()->manages('libre'));
        self::assertFalse($this->make()->manages('deepl'));
        self::assertFalse($this->make(enabled: false)->manages('libre'));
    }

    public function testDisabledNeverScales(): void
    {
        $a = $this->make(enabled: false);
        $a->requestUp(5);
        $a->ensureReady();
        self::assertTrue($a->scaleDownIfIdle(0) === false);
        self::assertSame([], $this->scaler->calls);
    }

    public function testHealthyEngineIsNotScaled(): void
    {
        $a = $this->make();
        $a->ensureReady();
        self::assertSame([], $this->scaler->calls);
    }

    public function testColdEngineIsScaledUpAndWaitedFor(): void
    {
        $this->health = [503, 503, 503, 200];
        $a = $this->make();
        $a->ensureReady();
        self::assertSame([1], $this->scaler->calls);
        self::assertSame(4, $this->healthCalls);
    }

    public function testNeverReadyThrowsRecoverableWithDelay(): void
    {
        $this->health = [503];
        $a = $this->make(timeout: 20);
        try {
            $a->ensureReady();
            self::fail('expected exception');
        } catch (EngineNotReadyException $e) {
            self::assertSame(30_000, $e->getRetryDelay());
        }
        self::assertSame([1], $this->scaler->calls);
    }

    public function testScaleUpRequestsAreThrottledButRetriedAfterWindow(): void
    {
        $a = $this->make();
        $a->requestUp();
        $a->requestUp();
        self::assertSame([1], $this->scaler->calls);
        $this->clock->sleep(121);
        $a->requestUp();
        self::assertSame([1, 1], $this->scaler->calls);
    }

    public function testFailedScaleUpIsNotThrottled(): void
    {
        $a = $this->make();
        $this->scaler->fail = true;
        $a->requestUp();
        $this->scaler->fail = false;
        $a->requestUp();
        self::assertSame([1], $this->scaler->calls);
    }

    public function testIdleScaleDownRespectsQueueAndWindow(): void
    {
        $a = $this->make(idleMinutes: 10);
        $a->touch();

        $this->clock->sleep(9 * 60);
        self::assertFalse($a->scaleDownIfIdle(0), 'inside idle window');

        $this->clock->sleep(2 * 60);
        self::assertFalse($a->scaleDownIfIdle(3), 'queue not empty');
        self::assertSame([], $this->scaler->calls);

        self::assertTrue($a->scaleDownIfIdle(0));
        self::assertSame([0], $this->scaler->calls);
    }

    public function testActivityDefersScaleDown(): void
    {
        $a = $this->make(idleMinutes: 10);
        $a->touch();
        $this->clock->sleep(9 * 60);
        $a->touch();
        $this->clock->sleep(9 * 60);
        self::assertFalse($a->scaleDownIfIdle(0));
    }

    public function testNoActivityRecordStartsClockInsteadOfScalingDown(): void
    {
        $a = $this->make();
        self::assertFalse($a->scaleDownIfIdle(0));
        self::assertSame([], $this->scaler->calls);
    }

    public function testScaleUpAfterScaleDownIsNotThrottled(): void
    {
        $a = $this->make(idleMinutes: 1);
        $a->requestUp();
        $this->clock->sleep(61);
        self::assertTrue($a->scaleDownIfIdle(0));
        $a->requestUp();
        self::assertSame([1, 0, 1], $this->scaler->calls);
    }

    public function testReplicaCountIsCappedAndZeroWhenIdle(): void
    {
        $a = $this->make(max: 1);
        self::assertSame(0, $a->replicasFor(0, idle: false));
        self::assertSame(1, $a->replicasFor(500, idle: false));
        self::assertSame(0, $a->replicasFor(500, idle: true));
    }
}

final class SshEngineScalerTest extends TestCase
{
    public function testRefusesOutOfRangeCountsBeforeSpawningAnything(): void
    {
        $s = new \App\Service\EngineScaling\SshEngineScaler('x@y', '/nonexistent', maxReplicas: 1);
        foreach ([-1, 2] as $n) {
            try {
                $s->scaleTo($n);
                self::fail("scaleTo($n) should be refused");
            } catch (EngineScalingException $e) {
                self::assertStringContainsString('Refusing', $e->getMessage());
            }
        }
    }
}
