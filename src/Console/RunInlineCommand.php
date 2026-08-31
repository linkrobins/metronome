<?php

/*
 * This file is part of linkrobins/metronome.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Metronome\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Console\Schedule as FlarumSchedule;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * A drop-in replacement for `schedule:run` that never shells out.
 *
 * The stock scheduler launches every command event through
 * Process::fromShellCommandline(), which needs proc_open() - a function
 * plenty of shared hosts disable. This command walks the same schedule but
 * invokes each due command through the console application in-process.
 */
class RunInlineCommand extends AbstractCommand
{
    protected bool $eventsRan = false;

    /**
     * Core stamps this key when the stock schedule:run finishes; we stamp it
     * too (the admin dashboard reads it), plus our own twin key below. When
     * the shared key moves ahead of our twin, a stock runner executed the
     * schedule since we last did - almost always a leftover cron line.
     */
    protected const STOCK_STAMP = 'flarum:schedule:last_run';
    protected const OWN_STAMP = 'linkrobins-metronome:last_run';

    public function __construct(
        protected Container $container,
        protected Schedule $schedule,
        protected Dispatcher $events,
        protected ExceptionHandler $exceptions,
        protected Cache $cache,
        protected LoggerInterface $log
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('schedule:run-inline')
            ->setDescription('Run the scheduled commands in-process, without proc_open');
    }

    protected function fire(): int
    {
        $startedAt = Carbon::now();

        $this->warnIfStockSchedulerAlsoRuns();

        $schedule = $this->schedule;

        if (! $schedule instanceof FlarumSchedule) {
            // Core binds its own Schedule subclass, whose dueEvents() accepts
            // the plain container (wrapped in a maintenance-mode adapter). A
            // vanilla Illuminate schedule would need a full Laravel
            // Application, which Flarum doesn't have - refuse loudly rather
            // than guess at due events.
            $this->error('Expected Flarum\'s schedule binding; found '.get_class($schedule).'.');

            return self::FAILURE;
        }

        foreach ($schedule->dueEvents($this->container) as $event) {
            if (! $event->filtersPass($this->container)) {
                $this->events->dispatch(new ScheduledTaskSkipped($event));

                continue;
            }

            if ($event->onOneServer && ! $schedule->serverShouldRun($event, $startedAt)) {
                $this->info(sprintf('Skipping [%s], already ran on another server.', $event->getSummaryForDisplay()));

                continue;
            }

            $this->runEvent($event);

            $this->eventsRan = true;
        }

        if (! $this->eventsRan) {
            $this->info('No scheduled commands are ready to run.');
        }

        // The admin dashboard's scheduler status watches for the stock
        // `schedule:run` command finishing (core listens for its
        // CommandFinished event), so no matter how faithfully this command
        // runs the schedule, the dashboard would call the scheduler dead.
        // Stamp the same cache key core's listener writes, and our twin with
        // the identical instant so drift between them means a stock run.
        $now = Carbon::now();
        $this->cache->forever(self::STOCK_STAMP, $now);
        $this->cache->forever(self::OWN_STAMP, $now);

        return self::SUCCESS;
    }

    /**
     * A doubled cron (schedule:run-inline added alongside schedule:run
     * instead of replacing it) runs every scheduled task twice per minute.
     * withoutOverlapping() will not save anyone from that: it only stops
     * concurrent runs, not two fast sequential ones. Detect and nag.
     */
    protected function warnIfStockSchedulerAlsoRuns(): void
    {
        $stock = $this->cache->get(self::STOCK_STAMP);
        $own = $this->cache->get(self::OWN_STAMP);

        if (! $stock) {
            return;
        }

        $stock = Carbon::parse($stock);

        // An old stamp from before the switch to Metronome is fine; only a
        // fresh one means another runner is live right now.
        if ($stock->lt(Carbon::now()->subMinutes(10))) {
            return;
        }

        if ($own && $stock->lte(Carbon::parse($own))) {
            return;
        }

        $warning = 'The stock schedule:run also ran in the last few minutes. If schedule:run-inline was added '
            .'as a second cron line, remove the schedule:run line: running both executes every scheduled task '
            .'twice. If you just ran schedule:run by hand, ignore this.';

        $this->error($warning);
        $this->log->warning('[linkrobins/metronome] '.$warning);
    }

    protected function runEvent(Event $event): void
    {
        $summary = $event->getSummaryForDisplay();

        $this->info(sprintf('%s Running [%s]', Carbon::now()->format('Y-m-d H:i:s'), $summary));

        $this->events->dispatch(new ScheduledTaskStarting($event));

        $start = microtime(true);

        try {
            if ($event instanceof CallbackEvent) {
                // Closure events never touch Process - core's own machinery
                // (mutex, before/after callbacks, exit code) is safe as-is.
                $event->run($this->container);
            } else {
                $this->runCommandEventInProcess($event);
            }

            $this->events->dispatch(new ScheduledTaskFinished($event, round(microtime(true) - $start, 2)));

            if ($event->exitCode != 0) {
                throw new RuntimeException(sprintf('Scheduled command [%s] failed with exit code [%d].', $summary, $event->exitCode));
            }
        } catch (Throwable $e) {
            $this->events->dispatch(new ScheduledTaskFailed($event, $e));

            $this->exceptions->report($e);

            $this->error($e->getMessage());
        }
    }

    /**
     * Mirrors Illuminate's Event::run()/start()/finish() with the
     * Process::fromShellCommandline() call swapped for an in-process
     * invocation. The mutex bookkeeping has to match exactly: create on
     * entry, release on failure (like start()), and let finish() release it
     * after the after-callbacks (like run()).
     */
    protected function runCommandEventInProcess(Event $event): void
    {
        if ($event->runInBackground) {
            // Backgrounding needs a process launch. The honest fallback is
            // to run in the foreground and say so - never to skip silently.
            $this->error(sprintf(
                '[%s] wants runInBackground(); running it in the foreground instead, since backgrounding needs proc_open.',
                $event->getSummaryForDisplay()
            ));
        }

        if ($event->shouldSkipDueToOverlapping()) {
            $event->skippedBecauseOverlapping = true;

            return;
        }

        try {
            $event->callBeforeCallbacks($this->container);

            [$exitCode, $output] = $this->callSibling($event);
        } catch (Throwable $e) {
            if ($event->withoutOverlapping) {
                $event->mutex->forget($event);
            }

            throw $e;
        }

        $this->writeOutput($event, $output);

        $event->finish($this->container, $exitCode);
    }

    /**
     * Invoke the event's command through the console application we are
     * running under, and capture its output.
     *
     * @return array{0: int, 1: string}
     */
    protected function callSibling(Event $event): array
    {
        $commandLine = static::stripSchedulePrefix((string) $event->command);

        if ($commandLine === null) {
            // A $schedule->exec() shell command. There is genuinely no way
            // to run one of these without proc_open.
            throw new RuntimeException(sprintf(
                '[%s] is a shell command (exec()), which cannot run while proc_open is disabled.',
                $event->command
            ));
        }

        $application = $this->getApplication();

        if (! $application) {
            throw new RuntimeException('The console application is not available.');
        }

        $input = new StringInput($commandLine);
        $input->setInteractive(false);

        $output = new BufferedOutput();

        $exitCode = $application->doRun($input, $output);

        return [$exitCode, $output->fetch()];
    }

    /**
     * Reduce a scheduled event's shell string back to console-command input,
     * or return null for a command this runner cannot take over (exec()).
     *
     * $command holds the full shell string the stock scheduler would have
     * launched: '/path/to/php' 'flarum' command --args. Both halves of that
     * prefix come out of Artisan::formatCommandString() in the same process
     * that schedules the event, so stripping the same prefix back off is
     * exact - no guessing at quoting or binary paths.
     */
    public static function stripSchedulePrefix(string $command): ?string
    {
        $prefix = rtrim(Artisan::formatCommandString(''));

        if (! str_starts_with($command, $prefix.' ')) {
            return null;
        }

        return trim(substr($command, strlen($prefix)));
    }

    /**
     * Honour sendOutputTo()/appendOutputTo(), which the stock scheduler
     * implements as shell redirection in the command string.
     */
    protected function writeOutput(Event $event, string $output): void
    {
        if ($event->output === $event->getDefaultOutput() || $event->output === null) {
            return;
        }

        file_put_contents($event->output, $output, $event->shouldAppendOutput ? FILE_APPEND : 0);
    }
}
