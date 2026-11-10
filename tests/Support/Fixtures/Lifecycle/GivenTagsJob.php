<?php

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;

/**
 * A job whose tags() returns the tags it was built with, for the tests of
 * the tag normalisation.
 */
class GivenTagsJob extends BasicJob
{
    /**
     * @param  list<string>  $givenTags
     */
    public function __construct(public array $givenTags)
    {
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->givenTags;
    }
}
