<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

use Illuminate\Support\Facades\Gate;
use Justrau\Orrery\OrreryApplicationServiceProvider;

/**
 * The application's service provider, as an application writes it from the
 * published stub (stubs/OrreryServiceProvider.stub): the real
 * OrreryApplicationServiceProvider with a viewOrrery gate that allows two
 * e-mail addresses, and an operateOrrery gate that allows one of them.
 *
 * AuthorizationTest registers it in the cases that go through the gate. Its
 * boot() sets the static Orrery::$authUsing and Orrery::$operateUsing
 * callbacks.
 */
final class GateProvider extends OrreryApplicationServiceProvider
{
    /** May view and operate. */
    public const ALLOWED_EMAIL = 'allowed@authorization.test';

    /** May view, but not operate. */
    public const VIEWER_EMAIL = 'viewer@authorization.test';

    public const DENIED_EMAIL = 'denied@authorization.test';

    protected function gate(): void
    {
        Gate::define('viewOrrery', static function ($user = null): bool {
            return in_array($user?->email ?? '', [self::ALLOWED_EMAIL, self::VIEWER_EMAIL], true);
        });

        Gate::define('operateOrrery', static function ($user = null): bool {
            return in_array($user?->email ?? '', [self::ALLOWED_EMAIL], true);
        });
    }
}
