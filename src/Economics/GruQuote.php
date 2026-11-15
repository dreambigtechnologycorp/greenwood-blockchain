<?php
declare(strict_types=1);
namespace Greenwood\Economics;
use Greenwood\Crypto\ProtocolHash;

/** Verifies fixed, signed service quotations. This class does not attest usage or move funds. */
final class GruQuote
{
    public function __construct(private readonly array $authorities,private readonly int $threshold,private readonly int $gruScale,private readonly int $maxAgeBlocks,private readonly bool $referencePricingEnabled=false)
    {
        if($threshold<1 || $threshold>count($authorities) || count(array_unique($authorities))!==count($authorities) || $gruScale<1 || $maxAgeBlocks<1)throw new \InvalidArgumentException('Invalid quote authority/precision policy');
        foreach($authorities as $key)Policy::hex($key);
    }
    public function verify(string $body,array $approvals,string $chain,string $genesis,int $height): array
    {
        $q=CanonicalJson::decode($body);
        if(($q['chain']??null)!==$chain || ($q['genesis']??null)!==bin2hex($genesis) || ($q['gru_scale']??null)!==$this->gruScale
            || !is_int($q['issued_height']??null) || !is_int($q['expiry_height']??null) || $q['issued_height']<0 || $q['issued_height']>$height
            || $height>$q['expiry_height'] || $q['expiry_height']<$q['issued_height'] || $q['expiry_height']-$q['issued_height']>$this->maxAgeBlocks) throw new \InvalidArgumentException('Wrong-chain, stale or expired quote');
        foreach(['payer','provider','service_id','catalog_hash'] as $name)Policy::hex($q[$name]??null);
        $providerPriced=($q['price_kind']??null)==='provider_gwd';
        if($providerPriced){
            foreach(['gwd_units_per_gru_num','gwd_units_per_gru_den'] as $name)if(Amount::integer($q[$name]??null)<1)throw new \InvalidArgumentException('Invalid provider GWD/GRU rate');
            if(isset($q['price_usd_micros_per_gwd']) || $this->threshold!==1)throw new \InvalidArgumentException('Provider quote has ambiguous pricing or signer policy');
        }else{
            if(!$this->referencePricingEnabled || !in_array($q['price_kind']??null,['internal_reference','market_reference'],true))throw new \InvalidArgumentException('Reference-indexed pricing is not enabled');
            if(isset($q['gwd_units_per_gru_num']) || isset($q['gwd_units_per_gru_den']) || Amount::integer($q['price_usd_micros_per_gwd']??null)<1)throw new \InvalidArgumentException('Invalid reference pricing');
        }
        foreach(['max_gru_units','max_gwd_units'] as $name)if(Amount::integer($q[$name]??null)<1)throw new \InvalidArgumentException('Invalid quote ceiling/price');
        $digest=ProtocolHash::hash('GREENWOOD_GRU_QUOTE_V1',$body);$seen=[];
        foreach($approvals as $approval){$key=Policy::hex($approval['public_key']??null);$sig=$approval['signature']??'';
            if(isset($seen[$key]) || !in_array($key,$this->authorities,true) || !is_string($sig) || !preg_match('/^[a-f0-9]{128}$/D',$sig)
                || !sodium_crypto_sign_verify_detached(hex2bin($sig),$digest,hex2bin($key)))throw new \InvalidArgumentException('Invalid/duplicate quote approval');
            if($providerPriced && bin2hex(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',hex2bin($key)))!==$q['provider'])throw new \InvalidArgumentException('Provider offer must be signed by its payout identity');
            $seen[$key]=true;
        }
        if(count($seen)<$this->threshold)throw new \InvalidArgumentException('Quote threshold not reached');
        return $q;
    }
    /** Cumulative amounts avoid repeated per-receipt rounding. Full subsidy pays all rounding dust. */
    public function value(string $body,array $approvals,string $chain,string $genesis,int $height,int $cumulativeGruUnits,int $subsidyPercent): array
    {
        $verifiedQuote=$this->verify($body,$approvals,$chain,$genesis,$height);
        if($cumulativeGruUnits<0 || $cumulativeGruUnits>Amount::integer($verifiedQuote['max_gru_units']) || $subsidyPercent<0 || $subsidyPercent>100)throw new \InvalidArgumentException('GRU allowance exceeded');
        $providerPriced=$verifiedQuote['price_kind']==='provider_gwd';
        $denominator=Amount::multiply($this->gruScale,Amount::integer($verifiedQuote[$providerPriced?'gwd_units_per_gru_den':'price_usd_micros_per_gwd']));
        $multiplier=$providerPriced?Amount::integer($verifiedQuote['gwd_units_per_gru_num']):10_000*Amount::SCALE; // 1 GRU = 10,000 micro-USD; no GWD peg or redemption claim.
        $total=Amount::mulDiv($cumulativeGruUnits,$multiplier,$denominator,true);
        if($total>Amount::integer($verifiedQuote['max_gwd_units']))throw new \InvalidArgumentException('GWD quote ceiling exceeded');
        // Split the cumulative rounded charge, retaining exact conservation and a zero payer charge at 100% subsidy.
        $subsidy=Amount::mulDiv($total,$subsidyPercent,100);
        return ['provider_units'=>$total,'subsidy_units'=>$subsidy,'payer_units'=>$total-$subsidy];
    }
}
