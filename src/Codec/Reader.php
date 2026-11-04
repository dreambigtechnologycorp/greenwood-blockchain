<?php
declare(strict_types=1);
namespace Greenwood\Codec;

final class Reader
{
    private int $offset = 0;
    public function __construct(private readonly string $bytes, int $limit = 1048576)
    {
        if (strlen($bytes) > $limit) throw new \InvalidArgumentException('Message too large');
    }
    public function take(int $length): string
    {
        if ($length < 0 || $length > strlen($this->bytes) - $this->offset) throw new \InvalidArgumentException('Truncated encoding');
        $result = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $result;
    }
    public function u8(): int { return ord($this->take(1)); }
    public function u16(): int { return unpack('n', $this->take(2))[1]; }
    public function u32(): int { return unpack('N', $this->take(4))[1]; }
    public function u64(): int
    {
        $parts = unpack('Nhi/Nlo', $this->take(8));
        if ($parts['hi'] > 0x7fffffff) throw new \InvalidArgumentException('Integer exceeds signed 64-bit range');
        return ($parts['hi'] << 32) | $parts['lo'];
    }
    public function bytes(int $limit = 65536): string
    {
        $length = $this->u32();
        if ($length > $limit) throw new \InvalidArgumentException('Field too large');
        return $this->take($length);
    }
    public function end(): void
    {
        if ($this->offset !== strlen($this->bytes)) throw new \InvalidArgumentException('Trailing bytes');
    }
}
