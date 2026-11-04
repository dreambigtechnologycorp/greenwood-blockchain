<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Assignment;

/** Administrator-owned allocation approvals; refreshed on every transition.
 * An approval does not replace the receiver's independent finalized chain check.
 */
final class AllocationPolicy {
    public function __construct(private readonly string $path, private readonly ?\Greenwood\ControlPlane\SignedPolicySnapshot $signedPolicy = null) {}
    public function approved(array $document):bool {
        clearstatcache(true,$this->path);
        if(is_link($this->path)||!is_file($this->path)||(fileperms($this->path)&0022)!==0
            ||!in_array(fileowner($this->path),[0,posix_geteuid()],true))return false;
        $p=$this->signedPolicy?->read()??json_decode(file_get_contents($this->path),true,32,JSON_THROW_ON_ERROR);
        if(($p['schema_version']??null)!==1||!is_int($p['valid_until']??null)||$p['valid_until']<=time()
            ||!is_array($p['approved_generations']??null)||!array_is_list($p['approved_generations']))return false;
        // Full document hash binds account, operation, app/release/digest, provider,
        // instance, endpoint, port, version and predecessor. No wildcards.
        return in_array(AssignmentDocument::generation($document),$p['approved_generations'],true);
    }
}
