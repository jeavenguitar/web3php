<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Eth;
use Web3php\Exception\RpcException;
use Web3php\Wallet;

/**
 * 广播交易的错误语义：
 *   - 链上拒绝（nonce too low、余额不足、revert 等）不抛异常
 *     → 返回 ['ok' => false, 'hash' => null, 'error' => 报错文本]；
 *   - 节点不可达（传输层失败，rpcCode=0）仍抛 RpcException。
 */
final class SendRawTransactionTest extends TestCase
{
    use RpcMockTrait;

    private const HASH = '0x1111111111111111111111111111111111111111111111111111111111111111';
    private const TO = '0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf';
    private const KEY = '0x0000000000000000000000000000000000000000000000000000000000000001';

    public function testSuccessReturnsHash(): void
    {
        $eth = new Eth(self::rpc(['eth_sendRawTransaction' => self::HASH]));

        $this->assertSame(
            ['ok' => true, 'hash' => self::HASH, 'error' => null],
            $eth->sendRawTransaction('0x02f86b820138'),
        );
    }

    public function testRejectionReturnsErrorWithoutThrowing(): void
    {
        // 典型：nonce 太低被节点拒绝（无 revert data，只有 message）
        $eth = new Eth(self::rpc([
            'eth_sendRawTransaction' => new RpcException('RPC 错误 [-32000] nonce too low', -32000),
        ]));

        $result = $eth->sendRawTransaction('0x02f86b820138');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['hash']);
        $this->assertStringContainsString('nonce too low', $result['error']);
    }

    public function testRejectionWithRevertDataDecodesReason(): void
    {
        $eth = new Eth(self::rpc([
            'eth_sendRawTransaction' => new RpcException('RPC 错误 [3] execution reverted', 3, self::revertData('insufficient allowance')),
        ]));

        $result = $eth->sendRawTransaction('0x02f86b820138');

        $this->assertFalse($result['ok']);
        $this->assertSame('Error(string): insufficient allowance', $result['error']);
    }

    public function testTransportFailureStillThrows(): void
    {
        // rpcCode=0：重试与故障转移耗尽的传输层失败，不是链上报错
        $eth = new Eth(self::rpc([
            'eth_sendRawTransaction' => new RpcException('所有 RPC 尝试均失败。cURL 7：Connection refused'),
        ]));

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage('Connection refused');
        $eth->sendRawTransaction('0x02f86b820138');
    }

    public function testWeb3SendTransactionSurfacesBroadcastError(): void
    {
        $web3 = self::web3([
            'eth_getTransactionCount' => '0x5',
            'eth_chainId' => '0x38',
            'eth_gasPrice' => '0x77359400',
            'eth_estimateGas' => '0x5208',
            'eth_sendRawTransaction' => new RpcException('RPC 错误 [-32000] already known', -32000),
        ]);

        $result = $web3->sendTransaction(new Wallet(self::KEY), ['to' => self::TO, 'value' => '1']);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['hash']);
        $this->assertStringContainsString('already known', $result['error']);
    }
}
