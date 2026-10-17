<?php

declare(strict_types=1);

namespace Web3php\Rpc;

/**
 * @internal 传输层失败（连接失败 / HTTP 错误 / 非 JSON / 空响应），
 *            仅在 RpcClient 内部用于驱动"重试 + 换节点"，不对外抛出。
 */
final class TransportFailure extends \RuntimeException
{
}
