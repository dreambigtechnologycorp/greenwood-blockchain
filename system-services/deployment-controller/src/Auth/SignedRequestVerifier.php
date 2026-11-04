<?php
declare(strict_types=1);
namespace Greenwood\DeploymentController\Auth;
use Greenwood\Crypto\ProtocolHash;
final class SignedRequestVerifier {
    public function __construct(private readonly \PDO $db,private readonly string $audience) {}
    public static function signature(string $public,string $signature,string $bytes):string {
        if(!preg_match('/^[a-f0-9]{64}$/D',$public)||!preg_match('/^[a-f0-9]{128}$/D',$signature)||!sodium_crypto_sign_verify_detached(hex2bin($signature),$bytes,hex2bin($public)))throw new \RuntimeException('UNAUTHENTICATED');
        return bin2hex(ProtocolHash::hash('GREENWOOD_ACCOUNT_V1',hex2bin($public)));
    }
    public function verify(string $method,string $path,string $body,array $headers):string {
        $h=array_change_key_case($headers,CASE_LOWER);$nonce=$h['x-greenwood-nonce']??'';$issued=$h['x-greenwood-timestamp']??'';
        if(!preg_match('/^[a-f0-9]{64}$/D',$nonce)||!preg_match('/^[0-9]{10}$/D',$issued)||abs(time()-(int)$issued)>30)throw new \RuntimeException('UNAUTHENTICATED');
        $bytes=implode("\n",['GREENWOOD-REQUEST-AUTH-V1','chain_id=greenwood-physical-v1','audience='.$this->audience,'method='.$method,'path='.$path,'body_sha256='.hash('sha256',$body),'nonce='.$nonce,'issued_at='.$issued]);
        $account=self::signature($h['x-greenwood-public-key']??'',$h['x-greenwood-signature']??'',$bytes);
        $q=$this->db->prepare('INSERT OR IGNORE INTO auth_nonce VALUES(?,?)');$q->execute([$account.':'.$nonce,time()+60]);if($q->rowCount()!==1)throw new \RuntimeException('REPLAY');return $account;
    }
}
