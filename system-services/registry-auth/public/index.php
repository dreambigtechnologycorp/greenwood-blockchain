<?php

declare(strict_types=1);

use Greenwood\SystemServices\RegistryAuth\Database;
use Greenwood\SystemServices\RegistryAuth\RegistryAuthService;

require_once __DIR__
    . '/../autoload.php';

$config =
    require __DIR__
    . '/../src/bootstrap.php';

$db =
    Database::open(
        $config['database_path']
    );

$issuer =
    file_get_contents(
        $config['issuer_key_path']
    );

if ($issuer === false) {
    throw new RuntimeException(
        'Unable to read registry issuer key'
    );
}

$service =
    new RegistryAuthService(
        $db,
        $config['chain_id'],
        $config['rpc_endpoint'],
        $config['application_registry_contract'],
        $issuer
    );

header('Content-Type: application/json');

$method =
    strtoupper(
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    );

$path =
    parse_url(
        $_SERVER['REQUEST_URI']
        ?? '/',
        PHP_URL_PATH
    );

function body(): array
{
    $raw =
        file_get_contents(
            'php://input', false, null, 0, 8193
        );
    if (is_string($raw) && strlen($raw) > 8192) {
        throw new InvalidArgumentException('Request body too large');
    }

    if (
        $raw === false
        || $raw === ''
    ) {
        return [];
    }

    $decoded =
        json_decode(
            $raw,
            true,
            flags: JSON_THROW_ON_ERROR
        );

    if (!is_array($decoded)) {
        throw new InvalidArgumentException(
            'JSON object required'
        );
    }

    return $decoded;
}

function respond(
    int $status,
    array $body
): never {
    http_response_code($status);

    echo json_encode(
        $body,
        JSON_THROW_ON_ERROR
    );

    exit;
}

try {
    if (
        $method === 'GET'
        && $path === '/health'
    ) {
        respond(
            200,
            [
                'service' =>
                    'GREENWOOD_REGISTRY_AUTH_V1',
                'status' => 'READY',
            ]
        );
    }

    if (
        $method === 'GET'
        && $path === '/issuer-public-key'
    ) {
        respond(
            200,
            [
                'public_key' =>
                    bin2hex(
                        $service
                            ->issuerPublicKey()
                    ),
            ]
        );
    }

    if (
        $method === 'POST'
        && $path === '/challenge'
    ) {
        $input = body();

        if (($input['chain_id'] ?? null) !== $config['chain_id']
            || ($input['registry_contract_id'] ?? null) !== $config['application_registry_contract']) {
            respond(400, ['error' => 'Registry challenge scope mismatch']);
        }

        $challenge =
            $service->challenge(
                (int) (
                    $input[
                        'application_id'
                    ]
                    ?? 0
                ),
                (string) (
                    $input['service']
                    ?? ''
                ),
                is_array(
                    $input['actions']
                    ?? null
                )
                    ? $input['actions']
                    : ['push', 'pull']
            );

        respond(
            200,
            [
                'success' => true,
                'challenge' =>
                    $challenge,
            ]
        );
    }

    if (
        $method === 'POST'
        && $path === '/authorize'
    ) {
        $input = body();

        $publicKey =
            hex2bin(
                (string) (
                    $input['public_key']
                    ?? ''
                )
            );

        $signature =
            hex2bin(
                (string) (
                    $input['signature']
                    ?? ''
                )
            );

        if (
            $publicKey === false
            || $signature === false
        ) {
            respond(
                400,
                [
                    'success' => false,
                    'error' =>
                        'public_key and signature must be hex',
                ]
            );
        }

        $result =
            $service->authorize(
                (string) (
                    $input[
                        'challenge_id'
                    ]
                    ?? ''
                ),
                $publicKey,
                $signature
            );

        respond(
            ($result['success'] ?? false)
                ? 200
                : 403,
            $result
        );
    }

    if (
        in_array(
            $method,
            ['GET', 'POST'],
            true
        )
        && $path === '/token'
    ) {
        if ($method === 'GET') {
            $input = $_GET;
        } else {
            $contentType = strtolower(trim(
                explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]
            ));

            if ($contentType === 'application/json') {
                try {
                    $input = body();
                } catch (JsonException $e) {
                    throw new InvalidArgumentException(
                        'Invalid JSON token request'
                    );
                }
            } elseif (
                $contentType === 'application/x-www-form-urlencoded'
            ) {
                $raw = file_get_contents('php://input', false, null, 0, 8193);

                if (
                    $raw === false
                    || strlen($raw) > 8192
                ) {
                    throw new InvalidArgumentException(
                        'Invalid token request body'
                    );
                }

                $keys = [];

                foreach (explode('&', $raw) as $field) {
                    if ($field === '') {
                        continue;
                    }

                    $name = urldecode(
                        explode('=', $field, 2)[0]
                    );

                    if (
                        !in_array(
                            $name,
                            ['credential', 'service', 'scope'],
                            true
                        )
                        || array_key_exists($name, $keys)
                    ) {
                        throw new InvalidArgumentException(
                            'Invalid or duplicate token parameter'
                        );
                    }

                    $keys[$name] = true;
                }

                parse_str($raw, $input);
            } else {
                throw new InvalidArgumentException(
                    'Unsupported token request content type'
                );
            }
        }

        foreach (
            ['credential', 'service', 'scope'] as $field
        ) {
            if (
                isset($input[$field])
                && !is_string($input[$field])
            ) {
                throw new InvalidArgumentException(
                    'Invalid token request parameter'
                );
            }
        }

        $credential =
            (string) (
                $input['credential']
                ?? ''
            );

        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authorization !== '') {
            if ($credential !== '' || !preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/D', $authorization, $match)) {
                respond(403, ['error' => 'Invalid authorization']);
            }
            $decoded = base64_decode($match[1], true);
            if ($decoded === false || !preg_match('/^greenwood:([a-f0-9]{64})$/D', $decoded, $parts)) {
                respond(403, ['error' => 'Invalid authorization']);
            }
            $credential = $parts[1];
        }
        $result =
            $service->token(
                $credential,
                (string) (
                    $input['service']
                    ?? 'greenwood-registry'
                ),
                (string) (
                    $input['scope']
                    ?? ''
                )
            );

        respond(
            isset($result['error'])
                ? 403
                : 200,
            isset($result['error'])
                ? [
                    'success' => false,
                    'error' =>
                        $result['error'],
                ]
                : [
                    'token' =>
                        $result['token'],
                    'access_token' =>
                        $result['token'],
                    'expires_in' => $result['expires_in'],
                    'issued_at' => $result['issued_at'],
                ]
        );
    }

    respond(
        404,
        [
            'error' => 'NOT_FOUND',
        ]
    );
} catch (InvalidArgumentException $e) {
    respond(
        400,
        [
            'error' =>
                $e->getMessage(),
        ]
    );
} catch (Throwable $e) {
    respond(
        500,
        [
            'error' =>
                'INTERNAL_ERROR',
        ]
    );
}
