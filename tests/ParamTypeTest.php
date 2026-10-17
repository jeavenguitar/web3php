<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\ParamType;

final class ParamTypeTest extends TestCase
{
    public function testParseCanonical(): void
    {
        $cases = [
            'uint' => 'uint256',
            'int' => 'int256',
            'uint24' => 'uint24',
            'address' => 'address',
            'bool' => 'bool',
            'bytes' => 'bytes',
            'bytes32' => 'bytes32',
            'string' => 'string',
            'uint256[]' => 'uint256[]',
            'uint256[3]' => 'uint256[3]',
            'address[2][]' => 'address[2][]',
        ];
        foreach ($cases as $input => $canonical) {
            $this->assertSame($canonical, ParamType::parse($input)->canonical(), "解析 {$input}");
        }
    }

    public function testTupleCanonical(): void
    {
        $tuple = ParamType::parse('tuple', [
            ['type' => 'uint', 'name' => 'amount'],
            ['type' => 'bytes', 'name' => 'data'],
        ]);
        $this->assertSame('(uint256,bytes)', $tuple->canonical());

        $arrayOfTuple = ParamType::parse('tuple[]', [
            ['type' => 'uint'],
            ['type' => 'bytes'],
        ]);
        $this->assertSame('(uint256,bytes)[]', $arrayOfTuple->canonical());

        $nested = ParamType::parse('tuple[2]', [
            ['type' => 'uint8'],
            ['type' => 'tuple', 'components' => [
                ['type' => 'bool'],
                ['type' => 'address'],
            ]],
        ]);
        $this->assertSame('(uint8,(bool,address))[2]', $nested->canonical());
    }

    public function testDynamicDetection(): void
    {
        $this->assertTrue(ParamType::parse('string')->isDynamic());
        $this->assertTrue(ParamType::parse('bytes')->isDynamic());
        $this->assertFalse(ParamType::parse('bytes32')->isDynamic());
        $this->assertTrue(ParamType::parse('uint256[]')->isDynamic());
        $this->assertFalse(ParamType::parse('uint256[3]')->isDynamic());

        // 元素为动态类型 → 定长数组也是动态
        $this->assertTrue(ParamType::parse('string[2]')->isDynamic());

        // 含动态成员 → tuple 动态
        $dynamicTuple = ParamType::parse('tuple', [
            ['type' => 'uint256'],
            ['type' => 'string'],
        ]);
        $this->assertTrue($dynamicTuple->isDynamic());

        $staticTuple = ParamType::parse('tuple', [
            ['type' => 'uint256'],
            ['type' => 'address'],
        ]);
        $this->assertFalse($staticTuple->isDynamic());
    }

    public function testHeadSize(): void
    {
        $this->assertSame(32, ParamType::parse('uint256')->headSize());
        $this->assertSame(32, ParamType::parse('bytes32')->headSize());
        $this->assertSame(32, ParamType::parse('string')->headSize());   // 动态 → offset 字
        $this->assertSame(96, ParamType::parse('uint256[3]')->headSize());

        $staticTuple = ParamType::parse('tuple', [
            ['type' => 'uint256'],
            ['type' => 'bytes8'],
            ['type' => 'address'],
        ]);
        $this->assertSame(96, $staticTuple->headSize());
    }

    public function testRejectsBadTypes(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        ParamType::parse('uint7');
    }
}
