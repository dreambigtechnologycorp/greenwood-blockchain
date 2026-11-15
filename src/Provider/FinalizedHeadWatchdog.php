<?php
declare(strict_types=1);
namespace Greenwood\Provider;
use Greenwood\Economics\Policy;

/** Local monotonic safety deadline; does not reinterpret block heights as measured service time.
 * After every process restart, require observed chain progress before admitting service.
 */
final class FinalizedHeadWatchdog
{
    private ?int $height=null;
    private ?int $advancedAt=null;
    private ?int $lastClock=null;
    private ?string $hash=null;
    public function __construct(private readonly int $maxStaleSeconds=180,private readonly ?\Closure $clock=null)
    {if($maxStaleSeconds<60||$maxStaleSeconds>3600)throw new \InvalidArgumentException('Explicit bounded watchdog deadline required');}
    public function observe(int $height,string $hash): void
    {
        Policy::hex($hash);$now=$this->clock?($this->clock)():intdiv(hrtime(true),1000000000);
        if(!is_int($now)||$now<0||$this->lastClock!==null&&$now<$this->lastClock)throw new \RuntimeException('Watchdog clock reset');$this->lastClock=$now;
        if($height<1||$this->height!==null&&($height<$this->height||$height===$this->height&&$hash!==$this->hash))throw new \RuntimeException('Finalized head rollback/conflict');
        if($this->height===null){$this->height=$height;$this->hash=$hash;throw new \RuntimeException('Awaiting finalized progress after startup');}
        if($height>$this->height){$this->advancedAt=$now;$this->height=$height;$this->hash=$hash;}
        if($this->advancedAt===null||$now-$this->advancedAt>=$this->maxStaleSeconds)throw new \RuntimeException('Finalized financial head stale');
    }
}
