<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Tx\Rlp;
use Web3php\Utils\Hex;

/**
 * EIP 里给出的 RLP 标准测试向量。
 */
final class RlpTest extends TestCase
{
    public function testOfficialVectors(): void
    {
        $cases = [
            ['input' => '', 'expected' => '0x80'],                                    // 空串
            ['input' => 'dog', 'expected' => '0x83646f67'],                            // 短字符串
            ['input' => ['cat', 'dog'], 'expected' => '0xc88363617483646f67'],         // 字符串列表
            ['input' => 0, 'expected' => '0x80'],                                     // 整数 0
            ['input' => 15, 'expected' => '0x0f'],                                    // 单字节自编码
            ['input' => 1024, 'expected' => '0x820400'],                              // 两字节整数
            ['input' => [], 'expected' => '0xc0'],                                    // 空列表
            ['input' => [[], [[]], [[], [[]]]], 'expected' => '0xc7c0c1c0c3c0c1c0'],        // 嵌套列表（与 web3p 交叉核对一致）
        ];
        foreach ($cases as $case) {
            $encoded = Rlp::encode($case['input']);
            $this->assertSame($case['expected'], Hex::fromBin($encoded));
        }
    }

    public function testLongString(): void
    {
        // 56 字节字符串 → 0xb8 + 长度
        $text = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit';
        $this->assertSame(56, strlen($text));
        $expected = '0xb8384c6f72656d20697073756d20646f6c6f722073697420616d65742c20636f6e7365637465747572206164697069736963696e6720656c6974';
        $this->assertSame($expected, Hex::fromBin(Rlp::encode($text)));
    }

    public function testNegativeRejected(): void
    {
        $this->expectException(\Web3php\Exception\TxException::class);
        Rlp::encode(gmp_init(-1));
    }
}
