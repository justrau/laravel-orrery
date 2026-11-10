<?php

namespace Justrau\Orrery\Storage;

use LogicException;

/**
 * A caller used a package session after PackageSession closed it (a
 * disconnect(), the end of an operator lease, releaseAll(), or a retarget of
 * its connection name). The released Connection never reconnects: the caller
 * asks PackageSession::for() for the current session.
 */
final class PackageSessionReleased extends LogicException
{
    public function __construct(public readonly string $session)
    {
        parent::__construct("The package session [{$session}] was released, so it does not reconnect. Ask PackageSession::for() for the current session.");
    }
}
