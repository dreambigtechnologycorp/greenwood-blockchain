<?php
declare(strict_types=1);
namespace Greenwood\Consensus\Runtime;
use Greenwood\Consensus\Messages\{Message,Certificate};
use Greenwood\Consensus\Journal\SafetyJournal;
use Greenwood\Consensus\Network\Transport;
use Greenwood\Node\Chain;
use Greenwood\Node\ChainConfig;
use Greenwood\Block\Block;
use Greenwood\Crypto\ProtocolHash;
use Greenwood\Transaction\Envelope;

final class ConsensusDriver
{
    private int $height;
    private int $round=0;
    private float $started;
    private float $heightStarted;
    private float $gossip=0;
    private float $sync=0;
    private array $messages=[];
    private array $votes=[];
    private array $blocks=[];
    private array $proposals=[];
    private array $pool=[];
    private ?array $lock=null;
    private ?array $valid=null;
    private const FAST_TIMEOUT_SECONDS=0.5;
    /** @var array<string,int> */
    private array $roundWatermarks=[];
    private ?int $fastRound=null;
    private ?float $fastStarted=null;

    /** Phase-convergence hold is volatile, height-scoped, and may arm once per local round. */
    private ?int $phaseHoldRound=null;
    private ?float $phaseHoldStarted=null;
    /** @var array<string,true> */
    private array $phaseHoldValidators=[];
    /** @var array<string,true> */
    private array $phaseHoldCaughtUp=[];
    private ?int $phaseHoldUsedRound=null;

    public Transport $transport;
    /** Optional operator listener (Unix socket / loopback + bearer); polled with the main loop. */
    public ?Transport $operatorTransport=null;
    private \Greenwood\Rpc\HttpApi $api;
    private \Greenwood\Rpc\RateLimiter $rateLimiter;
    private array $validators;
    private ChainConfig $config;

    public function __construct(public readonly Chain $chain,private readonly string $id,private readonly array $peers,private readonly ?SafetyJournal $journal,string $endpoint,?ChainConfig $config=null)
    {
        $this->config=$config ?? ChainConfig::defaults();
        $this->validators=$chain->genesis->getValidators(); ksort($this->validators,SORT_STRING);
        
        // Startup authorization: if we're running as a validator (have a journal), 
        // verify our validator ID is in the canonical validator set
        if ($journal !== null) {
            if (!isset($this->validators[$id])) {
                throw new \InvalidArgumentException(
                    'ConsensusDriver initialization failed: validator ID "' . $id . 
                    '" is not in the canonical validator set. ' .
                    'The node has cryptographic credentials but is not authorized to participate in consensus.'
                );
            }
        }
        
        $this->transport=new Transport($endpoint,fn($method,$path,$body)=>$this->handle($method,$path,$body));
        $this->api=new \Greenwood\Rpc\HttpApi(
            $this->chain,
            fn(string $body): string => $this->admitTransaction($body),
            fn(string $hash): bool => isset($this->pool[$hash]),
            fn(): int => count($this->pool),
            fn(): string => $this->journal ? 'validator' : 'full_node',
            fn(): bool => $this->journal !== null,
            fn(): int => $this->round,
            $this->config,
        );
        // Transport-layer abuse protection for the public mutation route only;
        // consensus and sync routes never pass through this limiter.
        $this->rateLimiter=new \Greenwood\Rpc\RateLimiter(20,5.0);
        $this->reset();
    }

    private function reset(): void
    {
        $this->height=$this->chain->tip->getHeight()+1; $this->round=0; $this->started=microtime(true); $this->heightStarted=$this->started;
        $this->messages=[]; $this->votes=[]; $this->blocks=[]; $this->proposals=[]; $this->valid=null;
        $this->roundWatermarks=[];
        $this->fastRound=null; $this->fastStarted=null;
        $this->resetPhaseHoldForRound();
        $this->lock=$this->journal?->getLock($this->height);
        if ($this->lock) {
            $b=Block::fromBytes($this->lock['block']); $this->chain->execute($b);
            Certificate::decode($this->lock['proof'])->verify($this->validators,$this->chain->genesis->getChainId(),$this->chain->genesis->getGenesisHash(),'prevote',$this->height,$b->getHash(),(int)$this->lock['round']);
            $this->blocks[bin2hex($b->getHash())]=$b; $this->valid=$this->lock;
        }
        foreach ($this->journal?->messages($this->height)??[] as $m) {
            $this->round=max($this->round,$m->round); $this->remember($m);
            if ($m->kind==='proposal') { $this->blocks[bin2hex($m->target)]=Block::fromBytes($m->block); $this->proposals[$m->round]=$m; }
        }
        foreach ($this->pool as $hash=>$tx) { try { (clone $this->chain->state)->applyTransaction($tx,$this->chain->genesis->getChainId(),$this->height); } catch (\InvalidArgumentException) { unset($this->pool[$hash]); } }
    }

    private function proposer(int $height,int $round): string { return array_keys($this->validators)[($height+$round)%count($this->validators)]; }

    /** Strict >2/3 quorum derived from the configured validator set (3-of-3 for A/B/C). */
    private function quorum(): int { return Certificate::quorum(count($this->validators)); }
    private function key(Message $m): string { return $m->round.':'.$m->kind.':'.$m->validator; }

    private function remember(Message $m): void
    {
        $this->messages[$this->key($m)]=$m;
        if ($m->kind!=='proposal') $this->votes[$m->round][$m->kind][bin2hex($m->target)][$m->validator]=$m;
    }

    private function sign(string $kind,string $target,?Block $block=null,string $proof=''): void
    {
        if (!$this->journal || isset($this->messages[$this->round.':'.$kind.':'.$this->id])) return;
        $m=new Message($kind,$this->chain->genesis->getChainId(),$this->chain->genesis->getGenesisHash(),$this->height,$this->round,$this->id,$target,$block?->toBytes()??'',$proof);
        $m=$this->journal->sign($m); $this->accept($m); $this->broadcast($kind,$m->bytes());
    }

    private function broadcast(string $type,string $bytes): void
    {
        foreach ($this->peers as $id=>$endpoint) if ($id!==$this->id) $this->transport->request($endpoint,'/consensus/'.$type,Transport::envelope($type,$bytes));
    }

    public function accept(Message $m): void
    {
        $m->verify($this->validators,$this->chain->genesis->getChainId(),$this->chain->genesis->getGenesisHash());
        if ($m->height!==$this->height) throw new \InvalidArgumentException('Wrong height or round');

        // Authentication and current-height checks happen before watermark observation.
        $this->observeAuthenticatedRound($m->validator,$m->round);

        // The active-round window remains unchanged. High authenticated messages may update
        // volatile watermarks, but cannot enter consensus state outside this window.
        if ($m->round>$this->round+1 || $m->round<max(0,$this->round-2)) throw new \InvalidArgumentException('Wrong height or round');

        $key=$this->key($m);
        if (isset($this->messages[$key])) {
            if ($this->messages[$key]->bytes()!==$m->bytes()) throw new \InvalidArgumentException('Conflicting consensus message');
            return;
        }

        if ($m->kind==='proposal') {
            if ($m->validator!==$this->proposer($m->height,$m->round)) throw new \InvalidArgumentException('Wrong proposer');
            $block=Block::fromBytes($m->block);
            if ($block->getHash()!==$m->target) throw new \InvalidArgumentException('Wrong proposal target');
            $this->chain->execute($block);
            if ($m->proof!=='') {
                $r=Certificate::decode($m->proof)->verify($this->validators,$m->chain,$m->genesis,'prevote',$m->height,$m->target);
                if ($r >= $m->round || $block->getHeader()->getRound()>$r) throw new \InvalidArgumentException('Invalid proposal proof round');
            } elseif ($block->getHeader()->getRound()!==$m->round) throw new \InvalidArgumentException('Reproposal needs proof');
            $this->blocks[bin2hex($m->target)]=$block; $this->proposals[$m->round]=$m;
        }

        $this->remember($m);
        $now=microtime(true);

        // If a bounded phase hold is active, only valid accepted messages from the exact
        // current round count as proof that one of the tracked laggards actually caught up.
        $this->observePhaseCatchup($m->validator,$m->round,$now);

        // A second r-1 watermark can arrive outside tick(); arm immediately when possible.
        $this->armPhaseHoldIfEligible($now);

        // Two distinct authenticated validators in the next round justify catching up,
        // but never clear a lock or authorize signing without proposal verification.
        if ($m->round===$this->round+1) {
            $seen=[]; foreach ($this->messages as $v) if ($v->round===$m->round) $seen[$v->validator]=true;
            if (count($seen)>=2) $this->enterRound($m->round,$now);
        }
    }

    private function observeAuthenticatedRound(string $validator,int $round): void
    {
        if (!array_key_exists($validator,$this->roundWatermarks) || $round>$this->roundWatermarks[$validator]) {
            $this->roundWatermarks[$validator]=$round;
        }
        $this->armFastIfEligible(microtime(true));
    }

    private function proposerAhead(): bool
    {
        $proposer=$this->proposer($this->height,$this->round);
        return array_key_exists($proposer,$this->roundWatermarks) && $this->roundWatermarks[$proposer]>$this->round;
    }

    private function armFastIfEligible(float $now): void
    {
        if (!$this->proposerAhead()) return;
        if ($this->fastRound===$this->round && $this->fastStarted!==null) return;
        $this->fastRound=$this->round; $this->fastStarted=$now;
    }

    private function resetFastForRound(float $now): void
    {
        $this->fastRound=null; $this->fastStarted=null;
        $this->armFastIfEligible($now);
    }

    private function fastTimedOut(float $now): bool
    {
        return $this->fastRound===$this->round
            && $this->fastStarted!==null
            && $now-$this->fastStarted>=self::FAST_TIMEOUT_SECONDS
            && $this->proposerAhead();
    }

    /** @return array<string,true> */
    private function laggingValidatorsExactlyOneRoundBehind(): array
    {
        if ($this->round<=0) return [];
        $lagging=[];
        $target=$this->round-1;
        foreach ($this->roundWatermarks as $validator=>$watermark) {
            if ($validator===$this->id) continue;
            if ($watermark===$target) $lagging[$validator]=true;
        }
        return $lagging;
    }

    private function canPhaseHoldCurrentRound(): bool
    {
        $prevote=$this->messages[$this->round.':prevote:'.$this->id]??null;
        $precommit=$this->messages[$this->round.':precommit:'.$this->id]??null;

        // Once a precommit escaped, this round cannot be repaired by delaying further.
        if ($precommit!==null) return false;

        // A locally signed NIL prevote likewise means this validator cannot contribute the
        // block prevote needed for a 3/4 quorum in the current round.
        if ($prevote!==null && $prevote->target==='') return false;

        return true;
    }

    private function armPhaseHoldIfEligible(float $now): void
    {
        if ($this->phaseHoldRound===$this->round) return;
        if ($this->phaseHoldUsedRound===$this->round) return;
        if (!$this->canPhaseHoldCurrentRound()) return;

        $lagging=$this->laggingValidatorsExactlyOneRoundBehind();
        if (count($lagging)<2) return;

        $this->phaseHoldRound=$this->round;
        $this->phaseHoldStarted=$now;
        $this->phaseHoldValidators=$lagging;
        $this->phaseHoldCaughtUp=[];
        // "Used" means armed once. It prevents expiry/completion from re-arming the same round.
        $this->phaseHoldUsedRound=$this->round;
    }

    private function observePhaseCatchup(string $validator,int $round,float $now): void
    {
        if ($this->phaseHoldRound!==$this->round || $this->phaseHoldStarted===null) return;
        if ($round!==$this->round) return;
        if (!isset($this->phaseHoldValidators[$validator])) return;

        $this->phaseHoldCaughtUp[$validator]=true;
        if (count($this->phaseHoldCaughtUp)<2) return;

        // Edge-triggered refresh: once two tracked laggards send valid current-round
        // messages, give the converged quorum one fresh phase window in this same round.
        $this->started=$now;
        $this->clearActivePhaseHold();
    }

    private function phaseHoldActive(float $now,float $unit): bool
    {
        if ($this->phaseHoldRound!==$this->round || $this->phaseHoldStarted===null) return false;

        // One bounded normal-round window from the time the hold armed.
        if ($now-$this->phaseHoldStarted>=3*$unit) {
            $this->clearActivePhaseHold();
            return false;
        }

        return true;
    }

    private function clearActivePhaseHold(): void
    {
        $this->phaseHoldRound=null;
        $this->phaseHoldStarted=null;
        $this->phaseHoldValidators=[];
        $this->phaseHoldCaughtUp=[];
    }

    private function resetPhaseHoldForRound(): void
    {
        $this->clearActivePhaseHold();
        $this->phaseHoldUsedRound=null;
    }

    private function enterRound(int $round,float $now): void
    {
        $this->round=$round;
        $this->started=$now;
        $this->resetPhaseHoldForRound();
        $this->resetFastForRound($now);
    }

    public function tick(): void
    {
        $this->transport->poll(); $this->operatorTransport?->poll(); $now=microtime(true);

        // Bound in-memory rounds; lock/valid proof remains durable independently.
        foreach ($this->messages as $key=>$message) if ($message->round < $this->round-2) unset($this->messages[$key]);
        foreach (array_keys($this->votes) as $round) if ($round < $this->round-2) unset($this->votes[$round]);
        foreach (array_keys($this->proposals) as $round) if ($round < $this->round-2) unset($this->proposals[$round]);
        $retain=[];
        foreach ($this->proposals as $p) $retain[bin2hex($p->target)]=true;
        foreach ([$this->lock,$this->valid] as $proof) if ($proof) $retain[bin2hex(Block::fromBytes($proof['block'])->getHash())]=true;
        foreach (array_keys($this->blocks) as $hash) if (!isset($retain[$hash])) unset($this->blocks[$hash]);

        if ($now-$this->sync>.5) { $this->sync=$now; $this->requestSync(); }
        if (!$this->journal) return;

        if ($now-$this->gossip>.35) {
            $this->gossip=$now;
            foreach ($this->messages as $m) if ($m->round >= $this->round-1) $this->broadcast($m->kind,$m->bytes());
            foreach ($this->pool as $tx) foreach ($this->peers as $id=>$endpoint) if ($id!==$this->id) $this->transport->request($endpoint,'/transactions',Transport::envelope('transaction',$tx->getCanonicalBytes()));
        }

        // Target block cadence drives the proposal gate (V1: 60 second blocks, which
        // is the mechanism for reducing excessive empty-block churn). The round
        // liveness timer stays decoupled so a failed proposer is skipped quickly
        // without producing empty blocks.
        $unit=min(10,1+$this->round*.5);
        // Round entry and phase holds must never reset this height-level gate.
        $proposalDelay=(float)$this->config->blockTimeSeconds();
        $this->armFastIfEligible($now);
        $this->armPhaseHoldIfEligible($now);
        $phaseHoldActive=$this->phaseHoldActive($now,$unit);

        // Compute elapsed after transport polling because accept() may have completed a
        // phase hold and refreshed $started during this same tick.
        $elapsed=$now-$this->started;

        if ($now-$this->heightStarted>=$proposalDelay && $this->proposer($this->height,$this->round)===$this->id && !isset($this->proposals[$this->round])) {
            if ($this->valid) { $block=Block::fromBytes($this->valid['block']); $proof=$this->valid['proof']; }
            else {
                $selected=[]; $state=clone $this->chain->state;
                $candidateBytes=strlen($this->chain->build([],$this->round,ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$this->validators[$this->id]['publicKey']))->toBytes());
                foreach ($this->pool as $tx) {
                    $txBytes=strlen($tx->getCanonicalBytes());
                    if ($txBytes>1048576-$candidateBytes) continue;
                    try { $state->applyTransaction($tx,$this->chain->genesis->getChainId(),$this->height); $selected[]=$tx; $candidateBytes+=$txBytes; } catch (\InvalidArgumentException) {}
                }
                $block=$this->chain->build($selected,$this->round,ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',$this->validators[$this->id]['publicKey'])); $proof='';
            }
            $this->sign('proposal',$block->getHash(),$block,$proof);
        }

        $proposal=$this->proposals[$this->round]??null;
        if ($proposal) {
            $allowed=$this->lock===null || Block::fromBytes($this->lock['block'])->getHash()===$proposal->target;
            if (!$allowed && $proposal->proof!=='') {
                $proofRound=Certificate::decode($proposal->proof)->votes[0]->round;
                $allowed=$proofRound>(int)$this->lock['round'];
            }
            $this->sign('prevote',$allowed?$proposal->target:'');
        } elseif ($elapsed>$unit && !$phaseHoldActive) {
            $this->sign('prevote','');
        }

        foreach ($this->votes[$this->round]['prevote']??[] as $hex=>$votes) {
            if (count($votes)<$this->quorum() || $hex==='' || !isset($this->blocks[$hex])) continue;
            $block=$this->blocks[$hex]; $proof=(new Certificate(array_values($votes)))->bytes();
            $this->valid=['round'=>$this->round,'block'=>$block->toBytes(),'proof'=>$proof];
            // Persist lock before any precommit signature can escape.
            if (!isset($this->messages[$this->round.':precommit:'.$this->id])) {
                $this->journal->lock($this->height,$this->round,$block->toBytes(),$proof); $this->lock=$this->valid;
                $this->sign('precommit',$block->getHash());
            }
        }

        if ($elapsed>2*$unit && !$phaseHoldActive) $this->sign('precommit','');

        foreach ($this->votes as $round=>$phases) foreach ($phases['precommit']??[] as $hex=>$votes) {
            if ($hex!=='' && count($votes)>=$this->quorum() && isset($this->blocks[$hex])) {
                $this->commit($this->blocks[$hex],(new Certificate(array_values($votes)))->bytes()); return;
            }
        }

        // FAST remains independent of the phase hold. It is still proposer-scoped,
        // authenticated, waits >=0.5s, and advances exactly one round.
        if ($this->fastTimedOut($now)) {
            $this->enterRound($this->round+1,$now);
        } elseif ($elapsed>3*$unit && !$phaseHoldActive) {
            $this->enterRound($this->round+1,$now);
        }
    }

    private function commit(Block $block,string $certificate): void
    {
        $this->chain->finalize($block,$certificate);
        echo json_encode(['event'=>'finalized','node'=>$this->id,'height'=>$block->getHeight(),'hash'=>bin2hex($block->getHash()),'root'=>bin2hex($block->getStateRoot())],JSON_THROW_ON_ERROR)."\n";
        $this->reset();
        // Finality transport carries block plus certificate in one canonical binary bundle.
        $this->broadcast('finality',\Greenwood\Codec\Canonical::encodeBytes($block->toBytes()).\Greenwood\Codec\Canonical::encodeBytes($certificate));
    }

    private function acceptFinality(string $bytes): void
    {
        $r=new \Greenwood\Codec\Reader($bytes,1100000); $b=Block::fromBytes($r->bytes(1048576)); $cert=$r->bytes(4096); $r->end();
        if ($b->getHeight()!==$this->height) throw new \InvalidArgumentException('Finality is not next height');
        $this->commit($b,$cert);
    }

    private function requestSync(): void
    {
        foreach ($this->peers as $id=>$endpoint) if ($id!==$this->id) {
            $height=$this->height;
            $this->transport->request($endpoint,'/sync/'.$height,null,function($data) use ($height) {
                if ($this->height!==$height) return;
                $this->acceptFinality(Transport::unwrap(json_encode($data,JSON_THROW_ON_ERROR),'finality'));
            });
        }
    }

    public function status(): array
    {
        $accounts=[]; foreach ($this->chain->state->getAccounts() as $a) $accounts[bin2hex($a->getAddress())]=['balance'=>$a->getBalance(),'nonce'=>$a->getNonce()];
        return ['mode'=>$this->journal?'validator':'full_node','validator_id'=>$this->journal?$this->id:null,'chain_id'=>$this->chain->genesis->getChainId(),'finalized_height'=>$this->chain->tip->getHeight(),'finalized_hash'=>bin2hex($this->chain->tip->getHash()),'state_root'=>bin2hex($this->chain->state->getStateRoot()),'round'=>$this->round,'accounts'=>$accounts];
    }

    /** Operator-only validator diagnostics. No signing keys, journals or key paths. */
    public function operatorStatus(): array
    {
        return [
            'role' => $this->journal ? 'validator' : 'full_node',
            'validator_id' => $this->journal ? $this->id : null,
            'chain_id' => $this->chain->genesis->getChainId(),
            'finalized_height' => $this->chain->tip->getHeight(),
            'finalized_hash' => bin2hex($this->chain->tip->getHash()),
            'state_root' => bin2hex($this->chain->state->getStateRoot()),
            'peer_count' => count($this->peers),
            'syncing' => false,
        ];
    }

    public function operatorConsensus(): array
    {
        return [
            'height' => $this->height,
            'round' => $this->round,
            'finalized_height' => $this->chain->tip->getHeight(),
            'finalized_hash' => bin2hex($this->chain->tip->getHash()),
            'mempool_size' => count($this->pool),
            'peer_count' => count($this->peers),
            'syncing' => false,
        ];
    }

    public function admitTransaction(string $body): string
    {
        $tx=Envelope::decode(Transport::unwrap($body,'transaction')); $hash=bin2hex($tx->getHash());
        (clone $this->chain->state)->applyTransaction($tx,$this->chain->genesis->getChainId(),$this->height);
        if (count($this->pool)>=1000 && !isset($this->pool[$hash])) throw new \InvalidArgumentException('Mempool full');
        $this->pool[$hash]=$tx; return $hash;
    }

    private function handle(string $method,string $path,string $body,array $headers=[],string $peer=''): array|\Greenwood\Rpc\HttpResponse
    {
        // Pass 5 public API: /v1 routes plus node-level readiness/metrics.
        if ($method==='POST' && $path==='/v1/transactions') $this->rateLimiter->check($peer);
        if (str_starts_with($path,'/v1/') || $path==='/ready' || $path==='/metrics') return $this->api->handle($method,$path,$body);
        if ($method==='GET' && $path==='/health') return ['ok'=>true];
        if ($method==='GET' && $path==='/status') return $this->status();
        if ($method === 'GET') {
            $providerView = \Greenwood\Rpc\ProviderQueries::handle($this->chain, $path);
            if ($providerView !== null) return $providerView;
        }
        if ($method==='GET' && preg_match('~^/sync/([0-9]{1,10})$~D',$path,$m)) {
            $b=$this->chain->files->loadBlock((int)$m[1]); $cert=$this->chain->files->loadCertificate((int)$m[1]);
            if (!$b || !$cert) throw new \InvalidArgumentException('Finalized block unavailable');
            return json_decode(Transport::envelope('finality',\Greenwood\Codec\Canonical::encodeBytes($b->toBytes()).\Greenwood\Codec\Canonical::encodeBytes($cert)),true,8,JSON_THROW_ON_ERROR);
        }
        if ($method==='POST' && $path==='/transactions') return ['accepted'=>true,'hash'=>$this->admitTransaction($body)];
        if ($method==='POST' && preg_match('~^/consensus/(proposal|prevote|precommit|finality)$~D',$path,$m)) {
            $bytes=Transport::unwrap($body,$m[1]);
            if ($m[1]==='finality') $this->acceptFinality($bytes);
            else { $msg=Message::decode($bytes); if ($msg->kind!==$m[1]) throw new \InvalidArgumentException('Wrong message type'); $this->accept($msg); }
            return ['accepted'=>true];
        }
        throw new \InvalidArgumentException('Unknown method or route');
    }
}
