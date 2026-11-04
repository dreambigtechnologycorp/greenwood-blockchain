<?php

declare(strict_types=1);
namespace Greenwood\Tests\Unit\Edge;
use Greenwood\Edge\ApacheIngressConfiguration;
use PHPUnit\Framework\TestCase;

final class ApacheIngressConfigurationTest extends TestCase
{
    private function route(): array {
        return ['application_id'=>42, 'service'=>'web', 'hostname' => 'gw-42-web.greenwoodblockchain.com', 'desired' => 'PUBLISHED', 'state' => 'HEALTHY',
            'binding' => ['address' => '10.77.0.100', 'port' => 20000]];
    }
    public function testUnknownHostsDeniedByFirstVhostAndListenerIsLoopbackOnly(): void {
        $config = (new ApacheIngressConfiguration())->render([$this->route()]);
        self::assertStringContainsString('Listen 127.0.0.1:8081', $config);
        self::assertLessThan(strpos($config, 'ServerName gw-42-web'), strpos($config, 'Require all denied'));
        self::assertStringContainsString('ProxyPass / http://10.77.0.100:20000/', $config);
        self::assertStringNotContainsString('ServerAlias', $config);
        self::assertStringNotContainsString('*:80', $config);
    }
    public function testEmptyRegistryNeverCreatesCatchAllProxy(): void {
        $config = (new ApacheIngressConfiguration())->render([]);
        self::assertStringNotContainsString('ProxyPass ', $config);
        self::assertStringContainsString('Require all denied', $config);
    }
    public function testInjectionAndUnpublishedRoutesRejected(): void {
        foreach ([['hostname', "gw-1-web.example.com\nInclude /tmp/evil"], ['state', 'WITHDRAWN']] as [$field, $value]) {
            $route = $this->route(); $route[$field] = $value;
            try { (new ApacheIngressConfiguration())->render([$route]); self::fail('Unsafe route accepted'); }
            catch (\InvalidArgumentException $e) { self::assertSame('Invalid authorized ingress route', $e->getMessage()); }
        }
    }
    public function testDuplicateHostIsRejectedRatherThanShadowed(): void {
        $this->expectException(\InvalidArgumentException::class);
        (new ApacheIngressConfiguration())->render([$this->route(), $this->route()]);
    }
    public function testApplicationAliasAndSourceBinding(): void {
        $route=$this->route();$route['hostname']='app-42.example.com';
        $config=(new ApacheIngressConfiguration(8081,'192.168.9.4'))->render([$route]);
        self::assertStringContainsString('ServerName app-42.example.com',$config);
        self::assertStringContainsString('ProxySourceAddress 192.168.9.4',$config);
        $route['application_id']=43;
        $this->expectException(\InvalidArgumentException::class);
        (new ApacheIngressConfiguration())->render([$route]);
    }
}
