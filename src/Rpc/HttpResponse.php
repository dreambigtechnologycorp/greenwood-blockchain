<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/**
 * Explicit transport response object (status / content_type / body / headers).
 * Transport serializes it directly; handlers returning plain arrays keep the
 * legacy JSON behavior for internal (non-/v1) routes.
 */
final class HttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, 'application/json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public static function error(ApiError $error): self
    {
        return self::json($error->toArray(), $error->httpStatus);
    }

    public static function text(string $body, string $contentType = 'text/plain; version=0.0.4; charset=utf-8'): self
    {
        return new self(200, $contentType, $body);
    }
}
