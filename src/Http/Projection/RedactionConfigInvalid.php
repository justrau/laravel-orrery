<?php

namespace Justrau\Orrery\Http\Projection;

use RuntimeException;

/**
 * The `orrery.redact` configuration has an invalid rule. Every
 * job-data read answers 500 `redaction_config_invalid` with `field`; no job
 * data is returned unredacted. The message and the field name the rule's
 * position only, never its text.
 */
final class RedactionConfigInvalid extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("The redaction configuration is invalid: {$field}.");
    }

    /**
     * @return array{error: string, message: string, field: string}
     */
    public function body(): array
    {
        return [
            'error' => 'redaction_config_invalid',
            'message' => 'The redaction configuration is invalid, so job data is not shown.',
            'field' => $this->field,
        ];
    }
}
