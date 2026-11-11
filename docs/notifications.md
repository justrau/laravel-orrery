# Notifications

When a queue waits longer than its threshold in `orrery.waits`, `orrery:tick` dispatches the event `Justrau\Orrery\Events\LongWaitDetected` and sends the notification `LongWaitDetected`. Set the routes in the `boot()` method of `app/Providers/OrreryServiceProvider.php`:

```php
use Justrau\Orrery\Orrery;

Orrery::routeMailNotificationsTo('admin@example.com');
Orrery::routeSlackNotificationsTo(config('services.slack.notifications.orrery_webhook_url'));
Orrery::routeSmsNotificationsTo('15556667777');
```

- Read a route from `config()`, not `env()`: after `php artisan config:cache`, `env()` returns null in a provider. For the Slack example, add `'orrery_webhook_url' => env('ORRERY_SLACK_WEBHOOK_URL')` under `services.slack.notifications` in `config/services.php`.
- A null or empty route turns its channel off.
- A Slack route that starts with `http://` or `https://` is a webhook: the package sends the legacy Slack message, and the optional second argument names the channel (`Orrery::routeSlackNotificationsTo($url, '#ops')`). Any other route (`#ops`, `C0123`) is a channel for the Slack Web API: the package sends a Block Kit message with the bot token of `services.slack.notifications.bot_user_oauth_token`.
- A channel whose package is not installed is skipped, with one log warning per day.
- One long-wait notification per queue and channel at most every 5 minutes. The cooldown lives in the default cache store; when that store keeps its entries for one process only (`array`, `null`), is a `failover` store, or is down, it lives in the package tables. A channel that fails is skipped for every queue for a short backoff, which grows after a further failure.
- Set `mail.mailers.smtp.timeout` to 5–10 seconds: a hanging mail server holds the tick phase until the mailer gives up. The legacy Slack webhook times out after 5 s; the Web API channel takes its HTTP client's default of 30 s.

## Long-wait incidents and recovery

A long wait is an incident with a start and an end, kept in the package tables:

- It starts at the first tick at which the queue reaches its threshold. While it lasts, each tick that still reaches the threshold sends the long-wait notification again, limited by the cooldown above.
- It ends after 3 consecutive minutes in which the queue is known and neither its oldest job nor its estimated time to clear reaches the threshold. Each minute counts once, also when several servers or a manual `orrery:tick` run in it. A tick at which the queue could not be measured, or is not measured at all, keeps the incident open and starts the count again. So a queue that moves around its limit sends one start and one recovery.
- When its queue connection is removed from the configuration, or the queue is no longer measured for an hour after its last long wait, the incident ends without an event. A long wait after such an hour starts a new incident.

At the end, `orrery:tick` dispatches `Justrau\Orrery\Events\LongWaitRecovered`, with the connection, the queue, the duration in seconds from the first to the last tick over the threshold (`seconds`), the start (`startedAt`) and the channels that got the start message (`channels`). The event fires once per incident, also when no channel got the start message. The package then sends the notification `LongWaitRecovered` only to those channels. A channel that is down, fails, or is not reached before the tick's share ends is tried again on the next ticks for 10 minutes; after that it is dropped, and the health events record `long_wait:recovery_dropped`. Set `notifications.long_wait.recovered` to `false` to keep the event and send no message. To send your own message, bind `Justrau\Orrery\Contracts\LongWaitRecoveredNotification` to your notification class, as for `LongWaitDetectedNotification`; it is built with `connection`, `queue` and `seconds`.

## Failure spikes

Set `notifications.failure_spike.per_minute` to send a notice when the failed jobs of one minute, on all queues together, reach that number. It is off by default.

- Each tick checks the minutes of the last 5 minutes whose counts are known. A minute whose count is unknown sends nothing.
- Each minute sends at most one notice. After a notice, each channel waits `notifications.failure_spike.cooldown` minutes. When no channel got the notice and a channel was not waiting for its cooldown (it was down or failed), a later tick tries the minute again while it is in the last 5 minutes.
- The notice names the count, the minute (UTC) and the three largest failure groups of that minute, by job class and exception class. It holds no message text and no payload.
- Every count in the notice is "at least": the minute may still be open, and a failure that the package sees late is counted when the minute is counted again.
- It goes to the long-wait routes, with the same down states for failing channels.

There is no stored log of the notices: the health events record only failed sends.

## Stuck jobs

The Stuck tab and the job detail show the jobs that look stuck, each with the facts behind its signal:

- **reservation expired:** a reserved job whose reservation (`reserved_at` + the connection's `retry_after`) has passed;
- **outcome not observed:** a job whose attempts went past its observed outcomes (a worker died during an attempt);
- **repeated releases:** a job released at least `stuck.release_threshold` times in its current cycle;
- **attempts not capped:** a job with at least `stuck.attempt_threshold` attempts while Laravel enforces no attempt limit.

A running job also shows its worker's liveness (live, silent or unknown). That is a fact, not a stuck signal: a long job inside its reservation is live.

A job that becomes `unknown` (its row in `jobs` stayed absent for max(`retry_after`, 600) seconds without an observed outcome) also gets an incident, with the signal `unknown`. Two reasons get none, so no event and no notification: `connection_removed` (its queue connection is no longer configured), and `blind_gap`: the job left `jobs` during a [blind gap](operations.md#turning-orrery-off), a time in which no process recorded any activity (Orrery was switched off, or nothing recorded activity while the scheduler was down), so its outcome is not known. The Jobs list (the unknown tab, "Orrery was not observing") and the job detail show the reason. A job lost at any other time alerts as before.

The tick records one incident per job, cycle and signal and dispatches `Justrau\Orrery\Events\StuckJobDetected` for it, with the job's uuid, cycle, signal, connection, queue, name and the signal's numbers. The event carries no payload, exception text or context. Each incident is dispatched at most 3 times (again after 1 and 5 minutes when a listener throws). A tick that stops between a dispatch and its record can dispatch the same incident again, within those 3.

```php
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Events\StuckJobDetected;

Event::listen(function (StuckJobDetected $event) {
    logger()->warning('Stuck job', ['uuid' => $event->uuid, 'signal' => $event->signal]);
});
```

A late tick phase also sends each incident to the long-wait routes, without a listener:

- Each channel gets up to 3 tries per incident. A failed channel waits 60 s, then 300 s after the next failure, and the failure goes to the health events, never into the tick.
- One message per connection, queue, signal and channel every 5 minutes. The next message says "and N more", or a summary goes out when the 5 minutes end.
- The text holds only the event's values, never the payload. An SMS is at most 160 GSM characters.
- `ORRERY_STUCK_NOTIFY=false` sends no stuck-job notification and keeps the event. Turning it on again sends nothing old.
