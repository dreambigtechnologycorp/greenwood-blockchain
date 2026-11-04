<?php
declare(strict_types=1);
namespace Greenwood\Contracts;

interface GuestMemoryInterface
{
    public function checkRange(int $pointer,int $length): void;
    public function readBytes(int $pointer,int $length): string;
    public function writeBytes(int $pointer,string $bytes): void;
}
