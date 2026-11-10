<?php

namespace Workbench\App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

/**
 * A queued Slack notification. The seeder sends it to an anonymous Slack webhook route (a
 * canary) on a queue that no worker reads, so the payload keeps the route and nothing is sent.
 */
class WorkbenchAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue('payloads');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)->content('The Workbench import finished.');
    }
}
