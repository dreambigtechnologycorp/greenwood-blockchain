<?php
declare(strict_types=1);
namespace Greenwood\Node;
use Greenwood\Genesis\Genesis;
use Greenwood\Crypto\Ed25519KeyPair;
final class Configuration
{
    public static function load(string $path): array
    {
        $data=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        foreach (['protocol_version','wasm_abi_version','compute_version'] as $version) if (($data[$version]??1)!==1) throw new \InvalidArgumentException('Unsupported genesis version');
        $g=new Genesis($data['chain_id']); $peers=[]; $keys=[];
        $validatorCount=count($data['validators']);
        // The consensus implementation derives the strict >2/3 quorum from the
        // configured validator set (see Certificate::quorum). Reject degenerate
        // sets here; the canonical production deployment is exactly A/B/C.
        if ($validatorCount<3 || $validatorCount>128) throw new \InvalidArgumentException('Validator set must contain between 3 and 128 validators');
        foreach ($data['validators'] as $id=>$v) {
            if (!is_string($id) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D',$id) || $v['power']!==1) throw new \InvalidArgumentException('Invalid fixed validator');
            $key=self::hex($v['public_key'],32);
            if (isset($keys[$v['public_key']])) throw new \InvalidArgumentException('Duplicate validator key');
            $keys[$v['public_key']]=true;
            $g->addValidator($id,new Ed25519KeyPair($key,''),1); $peers[$id]=$v['endpoint'];
        }
        $addresses=[];
        if (count($data['accounts'])>10000) throw new \InvalidArgumentException('Too many genesis accounts');
        foreach ($data['accounts'] as $id=>$a) {
            if (!is_int($a['balance']) || $a['balance']<0) throw new \InvalidArgumentException('Invalid genesis balance');
            if (isset($addresses[$a['public_key']])) throw new \InvalidArgumentException('Duplicate genesis account');
            $addresses[$a['public_key']]=true;
            $g->addInitialAccount($id,new Ed25519KeyPair(self::hex($a['public_key'],32),''),$a['balance']);
        }
        return [$g,$peers];
    }
    public static function hex(string $hex,int $length): string
    {
        if (strlen($hex)!==$length*2 || !ctype_xdigit($hex) || strtolower($hex)!==$hex) throw new \InvalidArgumentException('Invalid hex');
        return hex2bin($hex);
    }
}
