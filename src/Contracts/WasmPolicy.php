<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

/** Conservative integer-only 3A profile, applied after native WASM validation.
 * Default: no imports. Explicit 3B profile permits only exact Greenwood signatures.
 * Both forbid start functions, nonempty elements and extensions. Data/custom bytes
 * are not scanned as instructions.
 */
final class WasmPolicy
{
    public static function validate(string $wasm, bool $hostImports = false): void
    {
        $r = new WasmPolicyReader($wasm);
        $r->take(8); $types=[];
        while (!$r->ended()) {
            $id=$r->byte(); $s=new WasmPolicyReader($r->take($r->u32()));
            switch ($id) {
                case 0: $s->skipRest(); break;
                case 1:
                    for ($n=$s->count(10000);$n>0;$n--) {
                        if ($s->byte()!==0x60) self::reject('Only function types are supported');
                        $params=[]; $results=[];
                        for ($p=$s->count(128);$p>0;$p--) { $t=$s->byte(); self::integerType($t); $params[]=$t; }
                        for ($p=$s->count(1);$p>0;$p--) { $t=$s->byte(); self::integerType($t); $results[]=$t; }
                        $types[]=[$params,$results];
                    }
                    break;
                case 2:
                    $count=$s->count(128);
                    if (!$hostImports && $count!==0) self::reject('All imports, including WASI, are forbidden in 3A');
                    $seen=[];
                    for ($i=0;$i<$count;$i++) {
                        $namespace=$s->take($s->u32()); $name=$s->take($s->u32());
                        if ($namespace!=='greenwood' || !isset(ContractAbi::FUNCTIONS[$name]) || $s->byte()!==0 || isset($seen[$name])) self::reject('Unknown, duplicate or nonfunction host import');
                        $seen[$name]=true; [$params,$result]=ContractAbi::FUNCTIONS[$name];
                        $expected=[array_map(static fn(int $k): int => $k===0?0x7f:0x7e,$params),[$result===0?0x7f:0x7e]];
                        if (($types[$s->u32()]??null)!==$expected) self::reject('Incorrect host import signature');
                    }
                    break;
                case 3:
                    for ($n=$s->count(10000);$n>0;$n--) $s->u32();
                    break;
                case 4:
                    for ($n=$s->count(1);$n>0;$n--) {
                        if ($s->byte()!==0x70) self::reject('Unsupported table type');
                        self::limits($s,WasmtimeConfig::MAX_TABLE_ELEMENTS);
                    }
                    break;
                case 5:
                    for ($n=$s->count(1);$n>0;$n--) self::limits($s,intdiv(WasmtimeConfig::MAX_MEMORY,65536));
                    break;
                case 6:
                    for ($n=$s->count(10000);$n>0;$n--) {
                        self::integerType($s->byte());
                        if ($s->byte()>1) self::reject('Invalid global mutability');
                        self::instructions($s);
                    }
                    break;
                case 7:
                    for ($n=$s->count(10000);$n>0;$n--) { $s->take($s->u32()); $s->byte(); $s->u32(); }
                    break;
                case 8: self::reject('Start functions are forbidden in 3A');
                case 9:
                    if ($s->u32()!==0) self::reject('Element segments are not supported in 3A');
                    break;
                case 10:
                    for ($n=$s->count(10000);$n>0;$n--) {
                        $body=new WasmPolicyReader($s->take($s->u32())); $locals=0;
                        for ($l=$body->count(65536);$l>0;$l--) {
                            $locals+=$body->count(65536); if ($locals>65536) self::reject('Too many locals');
                            self::integerType($body->byte());
                        }
                        self::instructions($body); $body->end();
                    }
                    break;
                case 11:
                    for ($n=$s->count(10000);$n>0;$n--) {
                        $flags=$s->u32();
                        if ($flags===2 && $s->u32()!==0) self::reject('Only memory zero is supported');
                        if (!in_array($flags,[0,2],true)) self::reject('Only active data segments are supported');
                        self::instructions($s); $s->take($s->u32());
                    }
                    break;
                default: self::reject('Unsupported section');
            }
            $s->end();
        }
    }
    private static function integerType(int $type): void
    {
        if ($type!==0x7f && $type!==0x7e) self::reject('Only i32/i64 value types are permitted');
    }
    private static function limits(WasmPolicyReader $r,int $limit): void
    {
        if ($r->u32()!==1) self::reject('Resource requires an explicit unshared 32-bit maximum');
        $min=$r->u32(); $max=$r->u32();
        if ($min>$max || $max>$limit) self::reject('Resource exceeds configured bound');
    }
    private static function instructions(WasmPolicyReader $r): void
    {
        $depth=1;
        while ($depth>0) {
            $op=$r->byte();
            if ($op===0x0b) { $depth--; continue; }
            if (in_array($op,[0x02,0x03,0x04],true)) {
                $type=$r->byte(); if (!in_array($type,[0x40,0x7f,0x7e],true)) self::reject('Unsupported block type');
                $depth++; continue;
            }
            if (in_array($op,[0x00,0x01,0x05,0x0f,0x1a,0x1b],true)) continue;
            if (in_array($op,[0x0c,0x0d,0x10,0x20,0x21,0x22,0x23,0x24],true)) { $r->u32(); continue; }
            if ($op===0x0e) { for ($n=$r->count(10000);$n>0;$n--) $r->u32(); $r->u32(); continue; }
            if ($op===0x11) { $r->u32(); if ($r->u32()!==0) self::reject('Only table zero supported'); continue; }
            if (in_array($op,[0x28,0x29,0x36,0x37],true) || ($op>=0x2c && $op<=0x35) || ($op>=0x3a && $op<=0x3e)) {
                $r->u32(); $r->u32(); continue;
            }
            if ($op===0x3f || $op===0x40) { if ($r->u32()!==0) self::reject('Only memory zero supported'); continue; }
            if ($op===0x41 || $op===0x42) { $r->signedImmediate($op===0x41?5:10); continue; }
            if (($op>=0x45 && $op<=0x5a) || ($op>=0x67 && $op<=0x8a) || in_array($op,[0xa7,0xac,0xad,0xc0,0xc1,0xc2,0xc3,0xc4],true)) continue;
            self::reject('Floating-point or unsupported instruction: '.dechex($op));
        }
    }
    private static function reject(string $message): never { throw new WasmValidationException($message); }
}

/** Bounded WASM LEB reader, deliberately separate from Greenwood's BE codec. */
final class WasmPolicyReader
{
    private int $offset=0;
    public function __construct(private readonly string $bytes) {}
    public function byte(): int { return ord($this->take(1)); }
    public function take(int $length): string
    {
        if ($length<0 || $length>strlen($this->bytes)-$this->offset) throw new WasmValidationException('Truncated WASM policy input');
        $out=substr($this->bytes,$this->offset,$length); $this->offset+=$length; return $out;
    }
    public function u32(): int
    {
        $value=0;
        for ($i=0;$i<5;$i++) {
            $b=$this->byte(); if ($i===4 && $b>15) throw new WasmValidationException('Invalid u32 LEB');
            $value|=($b&127)<<($i*7); if (($b&128)===0) return $value;
        }
        throw new WasmValidationException('Invalid LEB');
    }
    public function signedImmediate(int $limit): void
    {
        for ($i=0;$i<$limit;$i++) if (($this->byte()&128)===0) return;
        throw new WasmValidationException('Invalid signed LEB');
    }
    public function count(int $max): int
    {
        $n=$this->u32(); if ($n>$max) throw new WasmValidationException('WASM count limit'); return $n;
    }
    public function ended(): bool { return $this->offset===strlen($this->bytes); }
    public function skipRest(): void { $this->offset=strlen($this->bytes); }
    public function end(): void { if (!$this->ended()) throw new WasmValidationException('Trailing WASM section bytes'); }
}
