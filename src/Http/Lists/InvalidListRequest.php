<?php

namespace Justrau\Orrery\Http\Lists;

use InvalidArgumentException;

/**
 * A list request that the engine refuses (HTTP 422): `error` is `invalid`
 * or `cursor_mismatch`, `field` names the parameter. The message never
 * repeats the value.
 */
final class InvalidListRequest extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message, public readonly string $error = 'invalid')
    {
        parent::__construct($message);
    }

    /**
     * @return array{error: string, message: string, field: string}
     */
    public function body(): array
    {
        return ['error' => $this->error, 'message' => $this->getMessage(), 'field' => $this->field];
    }
}
