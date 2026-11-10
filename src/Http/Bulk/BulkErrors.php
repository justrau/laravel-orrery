<?php

namespace Justrau\Orrery\Http\Bulk;

use Justrau\Orrery\Database\Schema\SchemaOutdated;

/**
 * The error codes of the bulk and per-job write routes.
 * resources/js/support/wording.js has a sentence for each.
 */
final class BulkErrors
{
    /** A malformed body or parameter (with `field`). */
    public const INVALID = 'invalid';

    public const NOT_FOUND = 'not_found';

    /** The token's signature or form is wrong, or it names an unknown key. */
    public const FROZEN_INVALID = 'frozen_invalid';

    /** The token is older than an hour at the first request, or the run was idle for more than an hour. */
    public const FROZEN_EXPIRED = 'frozen_expired';

    /** A forget whose confirmed total is not the frozen total. */
    public const CONFIRMATION_MISMATCH = 'confirmation_mismatch';

    /** Another request of the same ref with other frozen fields or another confirmation. */
    public const FROZEN_MISMATCH = 'frozen_mismatch';

    /** A forget freeze whose total is capped or search limited. */
    public const TOTAL_NOT_EXACT = 'total_not_exact';

    /** A forget freeze whose exact total is above orrery.bulk.forget_max (with `maximum`). */
    public const TOO_MANY = 'too_many';

    /** orrery.bulk.forget_max is not a whole number from 1 to 100,000: every bulk forget is refused. */
    public const CONFIG_INVALID = 'config_invalid';

    /** The mirror lacks the bulk run table or orrery_operations.bulk_ref (a schema before step 8). */
    public const SCHEMA_OUTDATED = SchemaOutdated::CODE;

    /** An empty app.key: a bulk forget cannot be signed. */
    public const APP_KEY_MISSING = 'app_key_missing';

    /** A read of the mirror failed (reported through the watcher). */
    public const READ_FAILED = 'read_failed';
}
