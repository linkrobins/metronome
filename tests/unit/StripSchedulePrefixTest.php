<?php

/*
 * This file is part of linkrobins/metronome.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Metronome\Tests\unit;

use Illuminate\Console\Application as Artisan;
use LinkRobins\Metronome\Console\RunInlineCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StripSchedulePrefixTest extends TestCase
{
    #[Test]
    public function a_scheduled_command_string_round_trips_back_to_console_input()
    {
        // Exactly what Schedule::command() produces for an event.
        $command = Artisan::formatCommandString("cache:clear --flag --marker='/tmp/some path/x'");

        $this->assertSame(
            "cache:clear --flag --marker='/tmp/some path/x'",
            RunInlineCommand::stripSchedulePrefix($command)
        );
    }

    #[Test]
    public function a_bare_command_name_round_trips()
    {
        $this->assertSame(
            'schedule:list',
            RunInlineCommand::stripSchedulePrefix(Artisan::formatCommandString('schedule:list'))
        );
    }

    #[Test]
    public function an_exec_shell_command_is_refused()
    {
        $this->assertNull(RunInlineCommand::stripSchedulePrefix('cp /a /b'));
        $this->assertNull(RunInlineCommand::stripSchedulePrefix('php -r "echo 1;"'));
    }

    #[Test]
    public function a_command_merely_prefixed_like_php_is_refused()
    {
        // The prefix match is exact (quoted binary + quoted script), so a
        // shell command that happens to start with an unquoted "php" is
        // still recognised as exec().
        $this->assertNull(RunInlineCommand::stripSchedulePrefix('php flarum cache:clear'));
    }
}
