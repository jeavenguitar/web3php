<?php

declare(strict_types=1);

namespace Web3php\Exception;

/**
 * JSON-RPC 层错误（节点返回 error、或所有重试/故障转移均失败）。
 */
class RpcException extends Web3Exception
{
    public function __construct(
        string $message,
        public readonly int $rpcCode = 0,
        public readonly mixed $rpcData = null,
    ) {
        parent::__construct($message);
    }
}
