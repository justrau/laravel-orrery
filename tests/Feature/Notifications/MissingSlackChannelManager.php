<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Notifications;

use Illuminate\Notifications\ChannelManager;
use InvalidArgumentException;

/**
 * A notification manager without the `slack` driver, as in an application
 * that did not install laravel/slack-notification-channel. The package is a
 * dev dependency here, so NotificationTest cannot rely on its absence.
 */
final class MissingSlackChannelManager extends ChannelManager
{
    public function driver($driver = null)
    {
        if ($driver === 'slack') {
            throw new InvalidArgumentException('Driver [slack] not supported.');
        }

        return parent::driver($driver);
    }
}
