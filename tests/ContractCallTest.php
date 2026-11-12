<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\Encoder;
use Web3php\Abi\ParamType;

/**
 * Contract::call() 的输出形态回归测试。
 *
 * 生产事故（2026-11-12，kinglong-donation-destruction /api/donation/info）：
 * getConfig 返回单个命名 struct（outputs[0].name = "config"），
 * Decoder::preferNamedKeys 会剥掉数字键，旧实现硬取 $decoded[0] 直接
 * "Undefined array key 0"。修复后单输出按解码顺序取首个值，命名/未命名都成立。
 */
final class ContractCallTest extends TestCase
{
    use RpcMockTrait;

    private const CONTRACT = '0xa47c7e825a40e929213f27b0b66eff863562a786';

    /** getConfig 同款 ABI：单输出命名 struct（address gd + uint256 min + bool open）。 */
    private const ABI_NAMED_TUPLE = '[
        {"type":"function","name":"getConfig","stateMutability":"view","inputs":[],"outputs":[{
            "name":"config","type":"tuple","internalType":"struct C.Config","components":[
                {"name":"gd","type":"address","internalType":"address"},
                {"name":"minDonateUsdt","type":"uint256","internalType":"uint256"},
                {"name":"openNow","type":"bool","internalType":"bool"}
            ]
        }]}
    ]';

    public function testSingleNamedTupleOutputReturnsStruct(): void
    {
        $tupleType = ParamType::parse('tuple', [
            ['type' => 'address', 'name' => 'gd'],
            ['type' => 'uint256', 'name' => 'minDonateUsdt'],
            ['type' => 'bool', 'name' => 'openNow'],
        ]);
        // 与真实返回一致：命名 tuple 解码后只留字段名键（数字键被 preferNamedKeys 剥掉）
        $raw = '0x'.Encoder::encode([$tupleType], [[
            'gd' => '0xF9Ff45a0c1e2e7C22064a29B390760b723C2Bd09',
            'minDonateUsdt' => '100000000000000000000',
            'openNow' => true,
        ]]);

        $contract = self::web3(['eth_call' => $raw])
            ->contract(self::ABI_NAMED_TUPLE)
            ->at(self::CONTRACT);

        $config = $contract->call('getConfig');

        $this->assertSame('0xF9Ff45a0c1e2e7C22064a29B390760b723C2Bd09', $config['gd']);
        $this->assertSame('100000000000000000000', $config['minDonateUsdt']);
        $this->assertTrue($config['openNow']);
    }

    public function testSingleUnnamedOutputStillReturnsScalar(): void
    {
        // 原有行为不回归：单未命名输出（如 ERC20 decimals/balanceOf）仍直接给标量
        $abi = '[{"type":"function","name":"decimals","stateMutability":"view","inputs":[],'
            .'"outputs":[{"type":"uint8"}]}]';
        $raw = '0x'.Encoder::encode([ParamType::parse('uint8')], [18]);

        $value = self::web3(['eth_call' => $raw])
            ->contract($abi)
            ->at(self::CONTRACT)
            ->call('decimals');

        $this->assertSame('18', $value);
    }

    public function testMultipleOutputsKeepFullArray(): void
    {
        $abi = '[{"type":"function","name":"getReserves","stateMutability":"view","inputs":[],"outputs":['
            .'{"name":"reserve0","type":"uint112"},{"name":"reserve1","type":"uint112"}]}]';
        $raw = '0x'.Encoder::encode(
            [ParamType::parse('uint112'), ParamType::parse('uint112')],
            ['1000', '4000'],
        );

        $value = self::web3(['eth_call' => $raw])
            ->contract($abi)
            ->at(self::CONTRACT)
            ->call('getReserves');

        // 全命名多输出：数字键被 preferNamedKeys 剥掉，只留字段名键（Decoder 既有契约）
        $this->assertSame(['reserve0' => '1000', 'reserve1' => '4000'], $value);
    }

    public function testNoOutputReturnsNull(): void
    {
        $abi = '[{"type":"function","name":"pause","stateMutability":"nonpayable","inputs":[],"outputs":[]}]';
        // 无输出函数也会发起 eth_call（节点回 '0x'），解码侧按 outputs=[] 直接给 null
        $value = self::web3(['eth_call' => '0x'])
            ->contract($abi)
            ->at(self::CONTRACT)
            ->call('pause');

        $this->assertNull($value);
    }
}
