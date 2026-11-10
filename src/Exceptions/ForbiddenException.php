<?php

namespace Justrau\Orrery\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A 403 of Authenticate: `forbidden` (a user is present and
 * the view check failed, or the configured guard is invalid) or
 * `forbidden_action` (the operate check of a write failed) or
 * `header_missing` (a write without the `X-Requested-With: XMLHttpRequest`
 * header that the SPA sends: a cross-site form cannot set it).
 *
 * An API route answers `{"error": <code>}`; the page route answers a plain
 * HTTP error (render() returns null, so Laravel renders the HttpException).
 */
final class ForbiddenException extends HttpException
{
    public const FORBIDDEN = 'forbidden';

    public const FORBIDDEN_ACTION = 'forbidden_action';

    public const HEADER_MISSING = 'header_missing';

    private function __construct(public readonly string $error, private readonly bool $json, string $message)
    {
        parent::__construct(403, $message);
    }

    public static function forbidden(bool $json): self
    {
        return new self(self::FORBIDDEN, $json, '');
    }

    public static function forbiddenAction(bool $json): self
    {
        return new self(self::FORBIDDEN_ACTION, $json, 'This action is unauthorized.');
    }

    public static function headerMissing(bool $json): self
    {
        return new self(self::HEADER_MISSING, $json, 'A write needs the X-Requested-With: XMLHttpRequest header.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $this->json ? new JsonResponse(['error' => $this->error], 403) : null;
    }
}
