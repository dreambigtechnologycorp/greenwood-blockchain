<?php
declare(strict_types=1);
namespace Greenwood\Rpc;

/**
 * Stable Pass 5 API error. Carries a machine code, HTTP status and bounded
 * details; never carries stack traces or internal exception text.
 *
 * HTTP mapping (frozen):
 *   400 INVALID_REQUEST   401 UNAUTHENTICATED  403 FORBIDDEN
 *   404 NOT_FOUND         409 CONFLICT         422 semantic validation failure
 *   429 RATE_LIMITED      503 NOT_READY        500 INTERNAL_ERROR
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly string $apiCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message, array $details = []): self
    {
        return new self('INVALID_REQUEST', $message, 400, $details);
    }
    public static function unauthenticated(string $message = 'Authentication required'): self
    {
        return new self('UNAUTHENTICATED', $message, 401);
    }
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self('FORBIDDEN', $message, 403);
    }
    public static function notFound(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 404, $details);
    }
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }
    public static function semantic(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }
    public static function rateLimited(string $message = 'Rate limit exceeded'): self
    {
        return new self('RATE_LIMITED', $message, 429);
    }
    public static function notReady(string $message, array $details = []): self
    {
        return new self('NOT_READY', $message, 503, $details);
    }
    public static function internal(string $message = 'Unexpected internal error'): self
    {
        return new self('INTERNAL_ERROR', $message, 500);
    }

    public function toArray(): array
    {
        return ['error' => ['code' => $this->apiCode, 'message' => $this->getMessage(), 'details' => (object) $this->details]];
    }
}
