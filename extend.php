<?php

use Flarum\Extend;
use LinkRobins\Metronome\Console\RunInlineCommand;

return [
    (new Extend\Console())
        ->command(RunInlineCommand::class),
];
