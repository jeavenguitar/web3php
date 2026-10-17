<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\Abi;

final class AbiResolveTest extends TestCase
{
    private function erc20Abi(): array
    {
        return [
            ['type' => 'function', 'name' => 'transfer', 'inputs' => [
                ['name' => 'to', 'type' => 'address'],
                ['name' => 'amount', 'type' => 'uint256'],
            ], 'outputs' => [['name' => '', 'type' => 'bool']], 'stateMutability' => 'nonpayable'],
            ['type' => 'function', 'name' => 'balanceOf', 'inputs' => [
                ['name' => 'account', 'type' => 'address'],
            ], 'outputs' => [['name' => '', 'type' => 'uint256']], 'stateMutability' => 'view'],
        ];
    }

    public function testLoadFromJsonString(): void
    {
        $abi = new Abi(json_encode($this->erc20Abi()));
        $this->assertTrue($abi->has('transfer'));
        $this->assertTrue($abi->has('balanceOf'));
        $this->assertFalse($abi->has('nonexistent'));
    }

    public function testSelectorComputed(): void
    {
        $abi = new Abi($this->erc20Abi());
        $this->assertSame('0xa9059cbb', $abi->method('transfer', 2)->selector);
        $this->assertSame('0x70a08231', $abi->method('balanceOf', 1)->selector);
    }

    public function testBySelector(): void
    {
        $abi = new Abi($this->erc20Abi());
        $method = $abi->bySelector('0xa9059cbb');
        $this->assertNotNull($method);
        $this->assertSame('transfer', $method->name);
        $this->assertNull($abi->bySelector('0xdeadbeef'));
    }

    public function testOverloadResolution(): void
    {
        $abi = new Abi([
            ['type' => 'function', 'name' => 'foo', 'inputs' => [['type' => 'uint256']], 'outputs' => []],
            ['type' => 'function', 'name' => 'foo', 'inputs' => [['type' => 'uint256'], ['type' => 'bool']], 'outputs' => []],
        ]);

        $this->assertCount(2, $abi->method('foo', 2)->inputs);
        $this->assertCount(1, $abi->method('foo', 1)->inputs);

        $this->expectException(\Web3php\Exception\AbiException::class);
        $this->expectExceptionMessage('重载');
        $abi->method('foo');
    }

    public function testTupleSignatureCanonicalized(): void
    {
        $abi = new Abi([
            ['type' => 'function', 'name' => 'batchSend', 'inputs' => [
                ['name' => 'items', 'type' => 'tuple[]', 'components' => [
                    ['name' => 'to', 'type' => 'address'],
                    ['name' => 'amount', 'type' => 'uint'],
                ]],
            ], 'outputs' => [['type' => 'bool']]],
        ]);

        $method = $abi->method('batchSend', 1);
        $this->assertSame('batchSend((address,uint256)[])', $method->signature);
    }

    public function testUnknownMethodListsAvailable(): void
    {
        $abi = new Abi($this->erc20Abi());
        try {
            $abi->method('mint');
            $this->fail('应抛异常');
        } catch (\Web3php\Exception\AbiException $e) {
            $this->assertStringContainsString('transfer', $e->getMessage());
            $this->assertStringContainsString('balanceOf', $e->getMessage());
        }
    }
}
