<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;

use Greenwood\ControlPlane\{MutualTlsChannel,SignedPolicySnapshot};
use Greenwood\DeploymentController\Auth\{DeploymentIdentity,FinalizedAuthority};
use Greenwood\DeploymentController\Registry\ReadOnlyRpc;
use Greenwood\DeploymentController\Provider\TimerAssignmentAuthority;

/** Protected composition shared by receivers, controller and edge admission. */
final class PhysicalAssignmentServices
{
    public static function config(string $path): array
    {
        if (!str_starts_with($path, '/')) throw new \RuntimeException('ABSOLUTE_CONFIGURATION_PATH_REQUIRED');
        for ($directory = dirname($path); ; $directory = dirname($directory)) {
            if (is_link($directory) || !is_dir($directory) || fileowner($directory) !== 0 || (fileperms($directory) & 0022) !== 0) {
                throw new \RuntimeException('PROTECTED_CONFIGURATION_DIRECTORY_REQUIRED');
            }
            if ($directory === '/') break;
        }
        if (is_link($path) || !is_file($path) || fileowner($path) !== 0 || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException('ROOT_OWNED_ASSIGNMENT_CONFIGURATION_REQUIRED');
        }
        $c = require $path;
        if (!is_array($c)) throw new \RuntimeException('INVALID_ASSIGNMENT_CONFIGURATION');
        return $c;
    }

    public static function build(array $c): array
    {
        $tls = $c['tls'];
        if (($tls['peer_identities'] ?? []) === [] || ($tls['peer_uris'] ?? []) === []) {
            throw new \RuntimeException('EXPLICIT_PEER_PINS_REQUIRED');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $c['approved_ca_sha256'] ?? '')
            || is_link($tls['ca']) || !is_file($tls['ca']) || fileowner($tls['ca']) !== 0 || (fileperms($tls['ca']) & 0022) !== 0
            || openssl_x509_fingerprint(file_get_contents($tls['ca']), 'sha256') !== $c['approved_ca_sha256']) {
            throw new \RuntimeException('APPROVED_CA_PIN_REQUIRED');
        }
        $revocations = new SignedPolicySnapshot($tls['revocations'], $c['policy_ledger'], 'revocations', $c['revocation_public_key'], $c['revocation_max_age']);
        $allocations = new SignedPolicySnapshot($c['approved_allocations_file'], $c['policy_ledger'], 'allocations', $c['allocation_public_key'], $c['allocation_max_age']);
        if ($c['revocation_public_key'] === $c['allocation_public_key']) throw new \RuntimeException('SEPARATE_POLICY_AUTHORITIES_REQUIRED');
        $revocations->read();
        $allocations->read();
        $channel = new MutualTlsChannel($tls['ca'], $tls['certificate'], $tls['private_key'], $tls['peer_identities'], $tls['peer_uris'], $tls['revocations'], $revocations);
        $authority = new FinalizedAuthority(ReadOnlyRpc::connect($c['rpc_endpoint'], DeploymentIdentity::CHAIN));
        $policy = new AllocationPolicy($c['approved_allocations_file'], $allocations);
        $authorize = static function (array $d) use ($authority, $revocations): bool {
            $revocations->read();
            $a = $d['assignment'];
            $proof = $authority->verify($d['account_id'], [
                'chain_id' => $d['chain_id'], 'registry_contract_id' => $d['registry_contract_id'],
                'application_id' => $a['application_id'], 'release_number' => $a['release_id'],
                'service' => $a['service'], 'image_digest' => $a['image_digest'],
            ]);
            return ($proof['authorized'] ?? false) === true;
        };
        return compact('channel', 'authority', 'policy', 'authorize');
    }

    public static function distributor(array $c, \PDO $db, array $services): AssignmentDistributor
    {
        return new AssignmentDistributor($db, ['node-1', 'node-4'],
            static fn(array $d): bool => ($services['authorize'])($d) && $services['policy']->approved($d),
            AssignmentDistributor::mtls($services['channel'], $c['assignment_receivers']));
    }

    public static function timer(array $c): TimerAssignmentAuthority
    {
        $s = self::build($c);
        $directory = dirname($c['distribution_ledger']);
        if (is_link($directory) || !is_dir($directory) || (fileperms($directory) & 0077) !== 0
            || fileowner($directory) !== posix_geteuid() || is_link($c['distribution_ledger'])
            || (file_exists($c['distribution_ledger']) && ((fileperms($c['distribution_ledger']) & 0077) !== 0
                || fileowner($c['distribution_ledger']) !== posix_geteuid()))) {
            throw new \RuntimeException('PRIVATE_DISTRIBUTION_LEDGER_REQUIRED');
        }
        $db = new \PDO('sqlite:' . $c['distribution_ledger']);
        chmod($c['distribution_ledger'], 0600);
        $distributor = self::distributor($c, $db, $s);
        return new TimerAssignmentAuthority($c['admissions_directory'], $distributor, $s['authority']->verify(...));
    }
}
