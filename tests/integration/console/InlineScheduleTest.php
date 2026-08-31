<?php

/*
 * This file is part of linkrobins/metronome.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Metronome\Tests\integration\console;

use Flarum\Extend;
use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use LinkRobins\Metronome\Tests\fixtures\ProbeCommand;
use PHPUnit\Framework\Attributes\Test;

class InlineScheduleTest extends ConsoleTestCase
{
    private string $marker;
    private string $outputFile;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-metronome');

        $this->marker = tempnam(sys_get_temp_dir(), 'si-marker-');
        $this->outputFile = tempnam(sys_get_temp_dir(), 'si-output-');

        // tempnam creates the files; the marker must not exist yet so its
        // presence proves execution.
        unlink($this->marker);
    }

    public function tearDown(): void
    {
        @unlink($this->marker);
        @unlink($this->outputFile);

        parent::tearDown();
    }

    private function scheduleProbe(callable $callback, array $args = []): void
    {
        $this->extend(
            (new Extend\Console())
                ->command(ProbeCommand::class)
                ->schedule(ProbeCommand::class, $callback, $args)
        );
    }

    private function probeEvent(): Event
    {
        $events = array_filter(
            $this->app()->getContainer()->make(Schedule::class)->events(),
            fn (Event $e) => str_contains((string) $e->command, 'metronome:probe')
        );

        $this->assertNotEmpty($events, 'the probe made it into the schedule');

        return reset($events);
    }

    #[Test]
    public function due_command_runs_in_process_with_args_and_output_capture()
    {
        $this->scheduleProbe(
            fn (Event $e) => $e->everyMinute()->sendOutputTo($this->outputFile),
            ['--marker' => $this->marker]
        );

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringContainsString('Running [', $output);
        $this->assertSame("probe-ran\n", file_get_contents($this->marker), 'the scheduled command really ran');
        $this->assertStringContainsString('probe says hello', file_get_contents($this->outputFile), 'sendOutputTo() captured the output');
    }

    #[Test]
    public function empty_schedule_reports_nothing_due_and_still_stamps_last_run()
    {
        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringContainsString('No scheduled commands are ready to run.', $output);

        $lastRun = $this->app()->getContainer()->make(Cache::class)->get('flarum:schedule:last_run');

        $this->assertNotNull($lastRun, 'the admin dashboard scheduler status key was stamped');
    }

    #[Test]
    public function without_overlapping_respects_a_held_mutex_and_runs_after_release()
    {
        $this->scheduleProbe(
            fn (Event $e) => $e->everyMinute()->withoutOverlapping(),
            ['--marker' => $this->marker]
        );

        $event = $this->probeEvent();

        // Simulate a run already in flight: hold the exact mutex the stock
        // scheduler would hold.
        $this->assertTrue($event->mutex->create($event));

        $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertFileDoesNotExist($this->marker, 'a held mutex skips the event');

        $event->mutex->forget($event);

        $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertFileExists($this->marker, 'the event runs once the mutex is released');
        $this->assertFalse($event->mutex->exists($event), 'the run released its own mutex afterwards');
    }

    #[Test]
    public function failing_command_is_reported_and_the_runner_finishes()
    {
        $this->scheduleProbe(
            fn (Event $e) => $e->everyMinute(),
            ['--marker' => $this->marker, '--exit' => '1']
        );

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertSame("probe-ran\n", file_get_contents($this->marker));
        $this->assertStringContainsString('failed with exit code [1]', $output);
    }

    #[Test]
    public function callback_events_run_through_core_machinery()
    {
        // Callback events cannot be registered through the extender; do what
        // an extension's service provider would and talk to the schedule.
        $marker = $this->marker;

        $this->app()->getContainer()->make(Schedule::class)
            ->call(function () use ($marker) {
                file_put_contents($marker, "callback-ran\n");
            })
            ->everyMinute();

        $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertSame("callback-ran\n", file_get_contents($this->marker));
    }

    #[Test]
    public function exec_events_fail_loudly_instead_of_silently()
    {
        $this->app()->getContainer()->make(Schedule::class)
            ->exec('echo hi')
            ->everyMinute();

        $output = $this->runCommand(['command' => 'schedule:run-inline']);

        $this->assertStringContainsString('cannot run while proc_open is disabled', $output);
    }
}
