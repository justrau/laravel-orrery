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

## Stuck jobs

The Stuck tab and the job detail show the jobs that look stuck, each with the facts behind its signal:

- **reservation expired:** a reserved job whose reservation (`reserved_at` + the connection's `retry_after`) has passed;
- **outcome not observed:** a job whose attempts went past its observed outcomes (a worker died during an attempt);
- **repeated releases:** a job released at least `stuck.release_threshold` times in its current cycle;
- **attempts not capped:** a job with at least `stuck.attempt_threshold` attempts while Laravel enforces no attempt limit.

A running job also shows its worker's liveness (live, silent or unknown). That is a fact, not a stuck signal: a long job inside its reservation is live.

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
