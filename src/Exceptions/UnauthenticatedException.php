<?php

namespace Justrau\Orrery\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The 401 of Authenticate: the view check failed and the
 * configured guard (orrery.guard) has no user.
 *
 * An API route answers `{"error": "unauthenticated"}`; the page route
 * answers a plain HTTP error.
 */
final class UnauthenticatedException extends HttpException
{
    public const ERROR = 'unauthenticated';

    private function __construct(private readonly bool $json)
    {
        parent::__construct(401, 'Unauthenticated.');
    }

    public static function make(bool $json): self
    {
        return new self($json);
    }

    public function render(Request $request): ?JsonResponse
    {
        return $this->json ? new JsonResponse(['error' => self::ERROR], 401) : null;
    }
}
