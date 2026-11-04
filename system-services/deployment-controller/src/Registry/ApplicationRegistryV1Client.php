<?php

declare(strict_types=1);

namespace Greenwood\DeploymentController\Registry;

use Greenwood\DeploymentController\Registry\GreenwoodRpc;


final class ApplicationRegistryV1Client
{
    public const CAP_PUBLISH = 0x0001;
    public const CAP_DEPLOY  = 0x0002;
    public const CAP_OPERATE = 0x0004;
    public const CAP_ADMIN   = 0x0008;
    public const CAP_ALL     = 0x000F;

    private string $contractIdHex;
    private ?int $finalizedHeight = null;

    public function atFinalizedHeight(int $height): void {
        if($height<1)throw new \InvalidArgumentException('Finalized height required');
        $this->finalizedHeight=$height;
    }

    public function __construct(
        private readonly GreenwoodRpc $rpc,
        string $contractId,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/D', $contractId) === 1) {
            $this->contractIdHex = $contractId;
            return;
        }

        if (strlen($contractId) === 32) {
            $this->contractIdHex = bin2hex($contractId);
            return;
        }

        throw new \InvalidArgumentException(
            'ApplicationRegistryV1 contract ID must be 32 raw bytes or 64 lowercase hex characters'
        );
    }

    public function contractId(): string
    {
        return $this->contractIdHex;
    }

    /**
     * Finalized read: application record.
     *
     * Layout:
     *   u32 version
     *   bytes32 controller
     *   bytes32 pending_controller
     *   u32 authority_epoch
     *   u32 active_release
     *   u32 release_count
     */
    public function application(int $applicationId): array
    {
        $bytes = $this->query('qap', self::u32($applicationId));

        if (strlen($bytes) !== 80) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 qap returned an invalid application record length'
            );
        }

        return [
            'application_id' => $applicationId,
            'version' => self::readU32($bytes, 0),
            'controller' => bin2hex(substr($bytes, 4, 32)),
            'pending_controller' => bin2hex(substr($bytes, 36, 32)),
            'authority_epoch' => self::readU32($bytes, 68),
            'active_release' => self::readU32($bytes, 72),
            'release_count' => self::readU32($bytes, 76),
        ];
    }

    /**
     * Finalized read: effective authority for an arbitrary account.
     */
    public function authority(
        int $applicationId,
        string $accountId
    ): int {
        $account = self::bytes32($accountId, 'account ID');

        $bytes = $this->query(
            'qau',
            self::u32($applicationId) . $account
        );

        if (strlen($bytes) !== 2) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 qau returned an invalid authority length'
            );
        }

        return unpack('nvalue', $bytes)['value'];
    }

    /**
     * Finalized read: one release header.
     *
     * Layout:
     *   u32 release_number
     *   bytes32 publisher
     *   u16 service_count
     */
    public function release(
        int $applicationId,
        int $releaseNumber
    ): array {
        $bytes = $this->query(
            'qre',
            self::u32($applicationId)
                . self::u32($releaseNumber)
        );

        if (strlen($bytes) !== 38) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 qre returned an invalid release record length'
            );
        }

        return [
            'application_id' => $applicationId,
            'release_number' => self::readU32($bytes, 0),
            'publisher' => bin2hex(substr($bytes, 4, 32)),
            'service_count' => unpack(
                'nvalue',
                substr($bytes, 36, 2)
            )['value'],
        ];
    }

    /**
     * Finalized read: digest for a named service in a release.
     */
    public function releaseServiceDigest(
        int $applicationId,
        int $releaseNumber,
        string $service
    ): string {
        self::assertServiceName($service);

        $bytes = $this->query(
            'qrs',
            self::u32($applicationId)
                . self::u32($releaseNumber)
                . $service
                . "\0"
        );

        if (strlen($bytes) !== 32) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 qrs returned an invalid digest length'
            );
        }

        return bin2hex($bytes);
    }

    /**
     * Finalized read: active release number.
     */
    public function activeRelease(int $applicationId): int
    {
        $bytes = $this->query(
            'qac',
            self::u32($applicationId)
        );

        if (strlen($bytes) !== 4) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 qac returned an invalid release number length'
            );
        }

        return self::readU32($bytes, 0);
    }

    private function query(
        string $method,
        string $calldata
    ): string {
        $result = $this->rpc->callContract(
            $this->contractIdHex,
            $method,
            base64_encode($calldata)
        );

        if($this->finalizedHeight===null||($result['finalized_height']??null)!==$this->finalizedHeight
            ||($result['contract_id']??null)!==$this->contractIdHex||($result['method']??null)!==$method) {
            throw new \RuntimeException('Registry query identity or finalized height mismatch');
        }
        if (($result['status'] ?? null) !== 'SUCCESS') {
            throw new \RuntimeException(
                sprintf(
                    'ApplicationRegistryV1 query %s failed with status %s',
                    $method,
                    (string) ($result['status'] ?? 'UNKNOWN')
                )
            );
        }

        $hex = $result['return_data'] ?? null;

        if (
            !is_string($hex)
            || preg_match(
                '/^(?:[0-9a-f]{2})*$/D',
                $hex
            ) !== 1
        ) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 returned invalid hex data'
            );
        }

        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new \UnexpectedValueException(
                'ApplicationRegistryV1 return data could not be decoded'
            );
        }

        return $bytes;
    }

    private static function u32(int $value): string
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new \InvalidArgumentException(
                'Value does not fit in unsigned 32-bit integer'
            );
        }

        return pack('N', $value);
    }

    private static function readU32(
        string $bytes,
        int $offset
    ): int {
        return unpack(
            'Nvalue',
            substr($bytes, $offset, 4)
        )['value'];
    }

    private static function bytes32(
        string $value,
        string $field
    ): string {
        if (
            preg_match('/^[0-9a-f]{64}$/D', $value)
            === 1
        ) {
            $decoded = hex2bin($value);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (strlen($value) === 32) {
            return $value;
        }

        throw new \InvalidArgumentException(
            $field
            . ' must be 32 raw bytes or 64 lowercase hex characters'
        );
    }

    private static function assertServiceName(
        string $service
    ): void {
        $length = strlen($service);

        if (
            $length < 1
            || $length > 64
            || str_contains($service, "\0")
        ) {
            throw new \InvalidArgumentException(
                'Service name must be 1-64 non-NUL bytes'
            );
        }
    }
}
