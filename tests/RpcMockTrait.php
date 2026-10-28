<?php

declare(strict_types=1);

namespace Web3php\Tests;

use Web3php\Abi\Encoder;
use Web3php\Abi\ParamType;
use Web3php\Eth;
use Web3php\Exception\RpcException;
use Web3php\Rpc\RpcClient;
use Web3php\Web3;

/**
 * 离线假节点：按 method 分发的 RpcClient / Web3（不发任何网络请求）。
 */
trait RpcMockTrait
{
    /** Error(string) 的 revert 数据：选择子 + ABI 编码的字符串（用自家编码器造，不手搓 hex） */
    private static function revertData(string $message): string
    {
        return '0x08c379a0' . Encoder::encode([ParamType::parse('string')], [$message]);
    }

    /** 按 method 分发的假 RpcClient；值为 RpcException 时抛出 */
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
}
