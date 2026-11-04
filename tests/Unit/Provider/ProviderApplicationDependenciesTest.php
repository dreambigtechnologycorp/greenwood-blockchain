<?php
declare(strict_types=1);

namespace Greenwood\Tests\Unit\Provider;

use Greenwood\Provider\ProviderApplicationDependencies;
use PHPUnit\Framework\TestCase;

final class ProviderApplicationDependenciesTest extends TestCase
{
    public function testInstalledGeneratedMessagesAndMetadataLoad(): void
    {
        ProviderApplicationDependencies::assertAvailable();
        $this->addToAssertionCount(1);
    }

    public function testIncompleteReleaseFailsBeforeAnyRuntimeOperation(): void
    {
        // Fresh process deliberately has no protobuf autoloader, matching the
        // partial G5 installation; it must not silently accept application work.
        $source = (new \ReflectionClass(ProviderApplicationDependencies::class))->getFileName();
        $script = 'require ' . var_export($source, true) . ';'
            . 'try { Greenwood\\Provider\\ProviderApplicationDependencies::assertAvailable(); exit(2); }'
            . 'catch (RuntimeException $e) { echo $e->getMessage(); exit(0); }';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertStringContainsString('Provider application dependencies incomplete:', $output);
        self::assertStringContainsString('GetImageRequest', $output);
    }
}
