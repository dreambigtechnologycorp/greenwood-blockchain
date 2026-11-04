<?php
declare(strict_types=1);
namespace Greenwood\Persistence;
use Greenwood\Block\Block;
final class BlockFilesystem
{
    public function __construct(private string $baseDirectory) { if (!is_dir($baseDirectory)) mkdir($baseDirectory,0700,true); }
    private function path(int $height): string { return $this->baseDirectory . '/' . sprintf('%016d-block.bin',$height); }
    public function saveBlock(Block $block): self
    {
        $path = $this->path($block->getHeight()); $bytes = $block->toBytes();
        if (is_file($path)) {
            if (file_get_contents($path) !== $bytes) throw new \RuntimeException('Conflicting authoritative block');
        } else AtomicFile::write($path,$bytes);
        return $this;
    }
    public function loadBlock(int $height): ?Block { return is_file($this->path($height)) ? Block::fromBytes(file_get_contents($this->path($height))) : null; }
    public function hasBlock(int $height): bool { return is_file($this->path($height)); }
    public function getAllBlockFiles(): array
    {
        $heights = [];
        foreach (scandir($this->baseDirectory) as $name) if (preg_match('/^(\d{16})-block.bin$/D',$name,$m)) $heights[] = (int)$m[1];
        sort($heights,SORT_NUMERIC); return $heights;
    }
    public function getHighestHeight(): int
    {
        $heights = $this->getAllBlockFiles();
        foreach ($heights as $i=>$height) if ($i !== $height) throw new \RuntimeException('Gap in authoritative history');
        return $heights === [] ? -1 : end($heights);
    }
    public function getBlockByHeight(int $height): ?Block { return $this->loadBlock($height); }
    public function getBlockHashByHeight(int $height): ?string { return $this->loadBlock($height)?->getHash(); }
    public function persistBlock(int $height,string $hash,string $bytes): self
    {
        $block = Block::fromBytes($bytes);
        if ($block->getHeight() !== $height || $block->getHash() !== $hash) throw new \InvalidArgumentException('Block identity mismatch');
        return $this->saveBlock($block);
    }
    public function persistCertificate(int $height,string $hash,string $bytes): self
    {
        AtomicFile::write($this->baseDirectory . '/' . sprintf('%016d-cert.bin',$height),$bytes); return $this;
    }
    public function loadCertificate(int $height): ?string
    {
        $path = $this->baseDirectory . '/' . sprintf('%016d-cert.bin',$height);
        return is_file($path) ? file_get_contents($path) : null;
    }
}
