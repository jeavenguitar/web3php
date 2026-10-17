<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\Decoder;
use Web3php\Abi\Encoder;
use Web3php\Abi\ParamType;

final class AbiCodecTest extends TestCase
{
    /**
     * ERC20 balanceOf / transfer 的全网公认 calldata。
     */
    public function testErc20KnownVectors(): void
    {
        $address = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

        $balanceOf = Encoder::encode(
            [ParamType::parse('address')],
            [$address],
        );
        $this->assertSame(
            '0000000000000000000000005aaeb6053f3e94c9b9a09f33669435e7ef1beaed',
            $balanceOf,
        );

        $transfer = Encoder::encode(
            [ParamType::parse('address'), ParamType::parse('uint256')],
            [$address, '1000000000000000000'],
        );
        $this->assertSame(
            '0000000000000000000000005aaeb6053f3e94c9b9a09f33669435e7ef1beaed'
            . '0000000000000000000000000000000000000000000000000de0b6b3a7640000',
            $transfer,
        );
    }

    /**
     * 全类型往返：编码再解码必须逐值还原（注意解码侧的类型归一化：
     * 大数 → 十进制字符串、地址 → 校验和、bytes → 0x hex）。
     */
    public function testRoundTripMixedTypes(): void
    {
        $types = [
            ParamType::parse('uint256'),
            ParamType::parse('int8'),
            ParamType::parse('int256'),
            ParamType::parse('bool'),
            ParamType::parse('address'),
            ParamType::parse('bytes31'),
            ParamType::parse('bytes'),
            ParamType::parse('string'),
            ParamType::parse('uint128[3]'),
            ParamType::parse('string[]'),
            // (uint8,(bool,address))[2]
            ParamType::parse('tuple[2]', [
                ['type' => 'uint8', 'name' => 'id'],
                ['type' => 'tuple', 'name' => 'pair', 'components' => [
                    ['type' => 'bool'],
                    ['type' => 'address'],
                ]],
            ]),
        ];

        $addr1 = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';
        $addr2 = '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359';

        $values = [
            '115792089237316195423570985008687907853269984665640564039457584007913129639935', // uint256 max
            '-100',
            '-57896044618658097711785492504343953926634992332820282019728792003956564819968', // int256 min
            true,
            $addr1,
            '0x' . str_repeat('ab', 31),
            '0xdeadbeef',
            '你好 web3php 🚀',
            ['1', '2', '3'],
            ['a', 'bb', ''],
            [
                [1, [true, $addr1]],
                [255, [false, $addr2]],
            ],
        ];

        $encoded = Encoder::encode($types, $values);
        $decoded = Decoder::decode($types, '0x' . $encoded);

        $this->assertSame($values[0], $decoded[0]);
        $this->assertSame('-100', $decoded[1]);
        $this->assertSame($values[2], $decoded[2]);
        $this->assertTrue($decoded[3]);
        $this->assertSame(strtolower($addr1), strtolower($decoded[4]));
        $this->assertSame('0x' . str_repeat('ab', 31), $decoded[5]);
        $this->assertSame('0xdeadbeef', $decoded[6]);
        $this->assertSame('你好 web3php 🚀', $decoded[7]);
        $this->assertSame(['1', '2', '3'], $decoded[8]);
        $this->assertSame(['a', 'bb', ''], $decoded[9]);

        // 嵌套 tuple：位置键 + 解码出的结构逐项对上
        $this->assertSame('1', $decoded[10][0][0]);
        $this->assertTrue($decoded[10][0][1][0]);
        $this->assertSame(strtolower($addr1), strtolower($decoded[10][0][1][1]));
        $this->assertSame('255', $decoded[10][1][0]);
        $this->assertFalse($decoded[10][1][1][0]);
        $this->assertSame(strtolower($addr2), strtolower($decoded[10][1][1][1]));
    }

    /**
     * struct 数组（tuple[]）的长度语义 = 元素个数。
     * 生产事故教训：按"总 slot 数"猜长度会把 50 个元素截断成 7 个。
     */
    public function testStructArrayLengthIsElementCount(): void
    {
        $components = [
            ['type' => 'uint256', 'name' => 'tokenId'],
            ['type' => 'address', 'name' => 'owner'],
            ['type' => 'uint8', 'name' => 'level'],
        ];
        $type = ParamType::parse('tuple[]', $components);

        $items = [];
        for ($i = 0; $i < 50; $i++) {
            $items[] = [
                'tokenId' => (string) ($i + 1),
                'owner' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
                'level' => $i % 5,
            ];
        }

        $encoded = Encoder::encode([$type], [$items]);
        $decoded = Decoder::decode([$type], '0x' . $encoded);

        $this->assertCount(50, $decoded[0]);
        $this->assertSame('1', $decoded[0][0]['tokenId']);
        $this->assertSame('50', $decoded[0][49]['tokenId']);
        $this->assertSame('4', $decoded[0][49]['level']);
    }

    /**
     * 输出命名：多值返回要带字段名键（数字键与名字键并存）。
     */
    public function testNamedOutputs(): void
    {
        $outputs = [
            ParamType::fromAbiItem(['type' => 'uint112', 'name' => 'reserve0']),
            ParamType::fromAbiItem(['type' => 'uint112', 'name' => 'reserve1']),
            ParamType::fromAbiItem(['type' => 'uint32', 'name' => 'blockTimestampLast']),
        ];
        $encoded = Encoder::encode($outputs, ['1000', '2000', '123456']);
        $decoded = Decoder::decode($outputs, '0x' . $encoded);

        $this->assertSame('1000', $decoded['reserve0']);
        $this->assertSame('2000', $decoded['reserve1']);
        $this->assertSame('123456', $decoded['blockTimestampLast']);
        $this->assertSame('1000', $decoded[0]);
        $this->assertSame('123456', $decoded[2]);
    }

    /**
     * tuple 内嵌动态类型（string）→ 动态 tuple，offset 必须以 tuple 数据区为基准。
     */
    public function testDynamicTupleWithNestedOffsets(): void
    {
        $type = ParamType::parse('tuple[]', [
            ['type' => 'uint256', 'name' => 'id'],
            ['type' => 'string', 'name' => 'name'],
            ['type' => 'address', 'name' => 'user'],
        ]);
        $items = [
            ['id' => '1', 'name' => 'alice', 'user' => '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'],
            ['id' => '2', 'name' => 'bob', 'user' => '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359'],
        ];

        $encoded = Encoder::encode([$type], [$items]);
        $decoded = Decoder::decode([$type], '0x' . $encoded);

        $this->assertCount(2, $decoded[0]);
        $this->assertSame('1', $decoded[0][0]['id']);
        $this->assertSame('alice', $decoded[0][0]['name']);
        $this->assertSame('2', $decoded[0][1]['id']);
        $this->assertSame('bob', $decoded[0][1]['name']);
    }

    public function testTruncatedDataThrows(): void
    {
        $types = [ParamType::parse('uint256'), ParamType::parse('uint256')];
        $encoded = Encoder::encode($types, ['1', '2']);
        // 掐掉最后一个字节
        $truncated = substr($encoded, 0, -2);

        $this->expectException(\Web3php\Exception\AbiException::class);
        $this->expectExceptionMessage('截断');
        Decoder::decode($types, '0x' . $truncated);
    }

    public function testEmptyDataWithOutputsThrows(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        Decoder::decode([ParamType::parse('uint256')], '0x');
    }

    public function testEmptyDataWithoutOutputsOk(): void
    {
        $this->assertSame([], Decoder::decode([], '0x'));
    }

    public function testUintRangeEnforced(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        Encoder::encode([ParamType::parse('uint8')], ['256']);
    }

    public function testDecodeKnownErrorString(): void
    {
        $payload = Encoder::encode([ParamType::parse('string')], ['insufficient balance']);
        $reason = Decoder::decodeKnownError('0x08c379a0' . $payload);
        $this->assertSame('Error(string): insufficient balance', $reason);

        $this->assertNull(Decoder::decodeKnownError('0x12345678'));
        $this->assertNull(Decoder::decodeKnownError(null));
    }

    public function testDecodePanic(): void
    {
        // Panic(uint256) selector + 0x11（算术溢出）
        $payload = Encoder::encode([ParamType::parse('uint256')], ['17']);
        $reason = Decoder::decodeKnownError('0x4e487b71' . $payload);
        $this->assertStringContainsString('算术溢出', $reason);
    }
}
