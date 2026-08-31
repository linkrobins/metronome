# Link Robins Metronome

[![Latest Version](https://img.shields.io/packagist/v/linkrobins/metronome)](https://packagist.org/packages/linkrobins/metronome)
[![Downloads](https://img.shields.io/packagist/dt/linkrobins/metronome)](https://packagist.org/packages/linkrobins/metronome)
[![License](https://img.shields.io/packagist/l/linkrobins/metronome)](https://github.com/linkrobins/metronome/blob/main/LICENSE)
[![Backend](https://github.com/linkrobins/metronome/actions/workflows/backend.yml/badge.svg)](https://github.com/linkrobins/metronome/actions/workflows/backend.yml)

**Keeps Flarum's scheduled tasks ticking on shared hosting.** Many shared
hosts disable PHP's `proc_open` function, and Flarum's scheduler depends on
it: every scheduled task is launched as a separate process. Metronome adds a
`schedule:run-inline` command that runs the exact same schedule inside the
one PHP process your cron job already starts, so nothing needs `proc_open`
at all.

## The problem it solves

On hosting with `proc_open` disabled, `php flarum schedule:run` looks fine:
it exits cleanly, and the admin dashboard even reports the scheduler as
active. But no scheduled task ever actually runs. The only trace is a line
buried in the log for every task, every minute:

```
The Process class relies on proc_open, which is not available on your PHP installation.
```

So extensions that clean up, sync, or aggregate on a schedule quietly do
nothing, and it looks like their bug.

## Install

```bash
composer require linkrobins/metronome
```

Enable **Link Robins Metronome** in the admin panel, then point your cron job
(or your host's "cron jobs" panel, which is exactly the environment this is
for) at the new command once per minute:

```
* * * * * php /path/to/flarum/flarum schedule:run-inline
```

That is the whole setup. There are no settings.

## What it runs, and how faithfully

`schedule:run-inline` walks the same schedule as `schedule:run` and keeps
the same behaviour wherever that is possible without launching processes:

- Due-time, environment and maintenance-mode checks work as before.
- `withoutOverlapping()` locks are honoured, and are compatible both ways
  with locks taken by the stock runner.
- `onOneServer()` is honoured.
- Before/after hooks, success/failure hooks and `sendOutputTo()` /
  `appendOutputTo()` output capture all work.
- Closure-based tasks run through Flarum's own machinery untouched.
- Failures are reported to the Flarum log, and the admin dashboard's
  scheduler status is updated the same way the stock runner updates it.

Two things cannot be identical without `proc_open`, and the command is
upfront about both instead of skipping work silently:

- A task that asks for `runInBackground()` runs in the foreground instead,
  with a warning in the output. It still runs.
- A task scheduled as a raw shell command via `exec()` cannot run at all
  without launching a process. It is reported as a failed task rather than
  ignored.

If your hosting does have `proc_open`, keep using the stock
`schedule:run`. This extension is for the hosts where that is not an option.

## Flarum compatibility

Flarum 2.0 only. There is no build for Flarum 1.8.

## Support

Found a bug or need help? [Open an issue](https://github.com/linkrobins/metronome/issues).
