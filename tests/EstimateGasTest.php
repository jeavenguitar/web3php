<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\Encoder;
use Web3php\Abi\ParamType;
use Web3php\Eth;
use Web3php\Exception\RpcException;
use Web3php\Exception\TxException;
use Web3php\Rpc\RpcClient;
use Web3php\Web3;
use Web3php\Wallet;

/**
 * estimateGas 的错误语义：
 *   - 链上 revert（RPC 业务错误）不抛异常 → 返回人类可读报错文本；
 *   - 节点不可达（传输层失败，rpcCode=0）仍抛 RpcException；
 *   - sendTransaction 拿到报错文本时抛 TxException（不能拿报错当 gas 用）。
 */
final class EstimateGasTest extends TestCase
{
    private const TO = '0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf';

    /** Error(string) 的 revert 数据：选择子 + ABI 编码的字符串（用自家编码器造，不手搓 hex） */
    private static function revertData(string $message): string
    {
        return '0x08c379a0' . Encoder::encode([ParamType::parse('string')], [$message]);
    }

    /** 按 method 分发的假 RpcClient（不发任何网络请求）；值为 RpcException 时抛出 */
    private static function rpc(array $handlers): RpcClient
    {
        return new class ($handlers) extends RpcClient {
            /** @param array<string,mixed> $handlers */
            public function __construct(private readonly array $handlers)
            {
                parent::__construct('http://127.0.0.1:8545');
            }

            public function request(string $method, array $params = []): mixed
            {
                $result = $this->handlers[$method]
                    ?? throw new RpcException('测试未预期的请求：' . $method);

                if ($result instanceof RpcException) {
                    throw $result;
                }

                return $result;
            }
        };
    }

    /** eth() 走假 RpcClient 的 Web3（sendTransaction 全流程也在假节点上跑） */
    private static function web3(array $handlers): Web3
    {
        $rpc = self::rpc($handlers);

        return new class ($rpc) extends Web3 {
            public function __construct(private readonly RpcClient $injected)
            {
                parent::__construct('http://127.0.0.1:8545');
            }

            public function eth(): Eth
            {
                return new Eth($this->injected);
            }
        };
    }

    private static function eth(array $handlers): Eth
    {
        return new Eth(self::rpc($handlers));
    }

    public function testSuccessReturnsGasAsDecimalString(): void
    {
        $eth = self::eth(['eth_estimateGas' => '0x5208']);

        $this->assertSame('21000', $eth->estimateGas(['to' => self::TO]));
    }

    public function testRevertReturnsDecodedReasonInsteadOfThrowing(): void
    {
        $eth = self::eth(['eth_estimateGas' => new RpcException(
            'RPC 错误 [3] execution reverted',
            3,
            self::revertData('ERC20: transfer amount exceeds balance'),
        )]);

        $this->assertSame(
            'Error(string): ERC20: transfer amount exceeds balance',
            $eth->estimateGas(['to' => self::TO]),
        );
    }

    public function testErrorWithoutRevertDataReturnsNodeMessage(): void
    {
        // 典型场景：主币余额不够付 gas，节点只给 message 不给 revert data
        $eth = self::eth(['eth_estimateGas' => new RpcException(
            'RPC 错误 [3] insufficient funds for gas * price + value',
            3,
            null,
        )]);

        $this->assertSame(
            'RPC 错误 [3] insufficient funds for gas * price + value',
            $eth->estimateGas(['to' => self::TO]),
        );
    }

    public function testTransportFailureStillThrows(): void
    {
        // rpcCode=0：重试与故障转移耗尽的传输层失败，不是链上报错
        $eth = self::eth(['eth_estimateGas' => new RpcException('所有 RPC 尝试均失败。cURL 7：Connection refused')]);

        $this->expectException(RpcException::class);
        $this->expectExceptionMessage('Connection refused');
        $eth->estimateGas(['to' => self::TO]);
    }

    public function testContractEstimateGasReturnsReasonOnRevert(): void
    {
        $abi = <<<'JSON'
        [
          {"type":"function","name":"transfer","inputs":[{"name":"to","type":"address"},{"name":"amount","type":"uint256"}],"outputs":[{"name":"","type":"bool"}],"stateMutability":"nonpayable"}
        ]
        JSON;
        $web3 = self::web3(['eth_estimateGas' => new RpcException(
            'RPC 错误 [3] execution reverted',
            3,
            self::revertData('ERC20: transfer amount exceeds balance'),
        )]);
        $token = $web3->contract($abi)->at('0x55d398326f99059fF775485246999027B3197955');

        $this->assertSame(
            'Error(string): ERC20: transfer amount exceeds balance',
            $token->estimateGas('transfer', [self::TO, '1000000'], self::TO),
        );
    }

    public function testSendTransactionSurfacesEstimateReason(): void
    {
        $web3 = self::web3([
            'eth_getTransactionCount' => '0x5',
            'eth_chainId' => '0x38',
            'eth_gasPrice' => '0x77359400',
            'eth_estimateGas' => new RpcException('RPC 错误 [3] execution reverted', 3, self::revertData('insufficient allowance')),
        ]);
        $wallet = new Wallet('0x0000000000000000000000000000000000000000000000000000000000000001');

        try {
            $web3->sendTransaction($wallet, ['to' => self::TO, 'value' => '1']);
            $this->fail('应当抛 TxException');
        } catch (TxException $e) {
            $this->assertStringContainsString('Error(string): insufficient allowance', $e->getMessage());
        }
    }

    public function testSendTransactionStillWorksWithNormalEstimate(): void
    {
        // 回归：正常估值（纯数字）不会被误判成报错，签名广播流程不受影响
        $web3 = self::web3([
            'eth_getTransactionCount' => '0x5',
            'eth_chainId' => '0x38',
            'eth_gasPrice' => '0x77359400',
            'eth_estimateGas' => '0x5208',
            'eth_sendRawTransaction' => '0x1111111111111111111111111111111111111111111111111111111111111111',
        ]);
        $wallet = new Wallet('0x0000000000000000000000000000000000000000000000000000000000000001');

        $this->assertSame(
            '0x1111111111111111111111111111111111111111111111111111111111111111',
            $web3->sendTransaction($wallet, ['to' => self::TO, 'value' => '1000000000000000000']),
        );
    }
}
