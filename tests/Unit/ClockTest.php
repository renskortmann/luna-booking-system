<?php

declare(strict_types=1);

namespace Macrolab\Tests\Unit;

use Macrolab\Clock;
use Macrolab\Config;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    protected function setUp(): void
    {
        Config::set(['app' => ['display_timezone' => 'Europe/Amsterdam']]);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testFreezeMakesNowDeterministic(): void
    {
        Clock::freeze('2026-09-14 08:30:00');

        self::assertSame('2026-09-14 08:30:00', Clock::sql());
        self::assertSame('2026-09-14 08:30:00', Clock::sql(), 'and it does not move');
    }

    public function testBareTimesAreReadInTheLabTimezone(): void
    {
        // 09:00 in Amsterdam in September is 07:00 UTC.
        $instant = Clock::parseInstant('2026-09-14 09:00');

        self::assertNotNull($instant);
        self::assertSame('2026-09-14 07:00:00', Clock::sql($instant));
    }

    public function testTimesWithAnOffsetAreTakenAtFaceValue(): void
    {
        self::assertSame('2026-09-14 07:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00+02:00')));
        self::assertSame('2026-09-14 09:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00Z')));
    }

    public function testOffsetWithoutAColonIsAlsoUnderstood(): void
    {
        self::assertSame('2026-09-14 07:00:00', Clock::sql(Clock::parseInstant('2026-09-14T09:00:00+0200')));
    }

    public function testRubbishIsRejectedRatherThanGuessed(): void
    {
        self::assertNull(Clock::parseInstant(''));
        self::assertNull(Clock::parseInstant('   '));
        self::assertNull(Clock::parseInstant('not a date'));
    }

    public function testLocalRendersInTheLabTimezone(): void
    {
        $instant = Clock::fromSql('2026-09-14 07:00:00');

        self::assertSame('2026-09-14 09:00', Clock::local($instant));
        self::assertSame('09:00', Clock::local($instant, 'H:i'));
    }

    public function testWinterTimeRendersAnHourDifferently(): void
    {
        // Same UTC hour, but CET rather than CEST.
        self::assertSame('08:00', Clock::local(Clock::fromSql('2026-12-14 07:00:00'), 'H:i'));
    }
}
