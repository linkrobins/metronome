<?php

/*
 * This file is part of linkrobins/metronome.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Metronome\Tests\integration\console;

use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

class DoubledCronWarningTest extends ConsoleTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-metronome');
    }

    private function cache(): Cache
    {
        return $this->app()->getContainer()->make(Cache::class);
    }

    private const WARNING = 'The stock schedule:run also ran';

    #[Test]
    public function a_fresh_stock_stamp_with_no_own_stamp_warns()
    {
        // What a leftover stock cron line produces: core's listener stamped
        // the shared key moments ago, and it was not us.
        $this->cache()->forever('flarum:schedule:last_run', Carbon::now()->subSeconds(30));

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringContainsString(self::WARNING, $output);
    }

    #[Test]
    public function a_stock_stamp_newer_than_our_own_warns()
    {
        $this->cache()->forever('linkrobins-metronome:last_run', Carbon::now()->subSeconds(90));
        $this->cache()->forever('flarum:schedule:last_run', Carbon::now()->subSeconds(30));

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringContainsString(self::WARNING, $output);
    }

    #[Test]
    public function an_old_stock_stamp_from_before_the_switch_does_not_warn()
    {
        $this->cache()->forever('flarum:schedule:last_run', Carbon::now()->subHours(3));

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringNotContainsString(self::WARNING, $output);
    }

    #[Test]
    public function steady_state_inline_runs_do_not_warn()
    {
        // First run stamps both keys with the same instant; the second run
        // must see no drift and stay quiet.
        $this->runCommand(['command' => 'schedule:run-inline']);

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringNotContainsString(self::WARNING, $output);
    }
}
