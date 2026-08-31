<?php

/*
 * This file is part of linkrobins/metronome.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Metronome\Tests\fixtures;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * A scheduled command stand-in: writes a marker file so tests can tell it
 * really ran, echoes a line so output capture can be asserted, and exits
 * with whatever code the schedule asked for.
 */
class ProbeCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('metronome:probe')
            ->setDescription('Test probe for the inline scheduler')
            ->addOption('marker', null, InputOption::VALUE_REQUIRED, 'File to touch as proof of execution')
            ->addOption('exit', null, InputOption::VALUE_REQUIRED, 'Exit code to return', '0');
    }

    protected function fire(): int
    {
        $marker = $this->input->getOption('marker');

        if ($marker) {
            file_put_contents($marker, "probe-ran\n", FILE_APPEND);
        }

        $this->output->writeln('probe says hello');

        return (int) $this->input->getOption('exit');
    }
}
