<?php

declare(strict_types=1);

namespace Web3php;

use Web3php\Abi\Decoder;
use Web3php\Exception\RpcException;
use Web3php\Rpc\RpcClient;
use Web3php\Utils\Hex;

/**
 * eth_* / net_* / web3_* API 封装（web3.js 风格）。
 *
 * 返回值约定：
 *   - 区块号、nonce、chainId 等 → int
 *   - 余额、gas 价格等大数     → 十进制字符串（wei 原始单位，不丢精度，配合 bcmath/GMP 使用）
 *   - 回执、交易、区块         → 原始对象（stdClass，字段与 JSON-RPC 一致）
 */
final class Eth
{
    public function __construct(private readonly RpcClient $rpc)
    {
    }

    public function rpc(): RpcClient
    {
        return $this->rpc;
    }

    public function blockNumber(): int
    {
        return self::quantityToInt($this->rpc->request('eth_blockNumber'));
    }

    public function chainId(): int
    {
        return self::quantityToInt($this->rpc->request('eth_chainId'));
    }

    public function clientVersion(): string
    {
        return (string) $this->rpc->request('web3_clientVersion');
    }

    /**
     * 网络 ID（EIP-155 用）。注意可能是很大的数，返回字符串。
     */
    public function netVersion(): string
    {
        return (string) $this->rpc->request('net_version');
    }

    /**
     * 同步状态：未同步返回 false，同步中返回进度对象。
     */
    public function syncing(): object|false
    {
        $result = $this->rpc->request('eth_syncing');

        return $result === false || $result === null ? false : (object) $result;
    }

    /**
     * 当前 gas 价格（wei，十进制字符串）。
     */
    public function gasPrice(): string
    {
        return self::quantityToString($this->rpc->request('eth_gasPrice'));
    }

    /**
     * EIP-1559 建议小费（wei）。节点不支持该方法是会抛 RpcException，
     * 调用方可用 suggestFees() 的兼容降级。
     */
    public function maxPriorityFeePerGas(): string
    {
        return self::quantityToString($this->rpc->request('eth_maxPriorityFeePerGas'));
    }

    /**
     * 费用建议：优先 EIP-1559，节点不支持时用 gasPrice 兜底
     * （maxFee = 2 × gasPrice，BSC 等链的常见实践）。
     *
     * @return array{legacy:string, maxPriorityFeePerGas:string, maxFeePerGas:string}
     */
    public function suggestFees(): array
    {
        $legacy = $this->gasPrice();
        try {
            $priority = $this->maxPriorityFeePerGas();
        } catch (RpcException) {
            $priority = $legacy;
        }
        $maxFee = gmp_strval(gmp_mul(Hex::toGmp($legacy), 2), 10);

        return [
            'legacy' => $legacy,
            'maxPriorityFeePerGas' => $priority,
            'maxFeePerGas' => $maxFee,
        ];
    }

    /**
     * 原生币余额（wei，十进制字符串）。
     */
    public function getBalance(string $address, string|int $block = 'latest'): string
    {
        return self::quantityToString($this->rpc->request('eth_getBalance', [$address, self::blockParam($block)]));
    }

    /**
     * 账户交易数（nonce）。
     */
    public function getTransactionCount(string $address, string|int $block = 'latest'): int
    {
        return self::quantityToInt($this->rpc->request('eth_getTransactionCount', [$address, self::blockParam($block)]));
    }

    /**
     * 合约字节码（0x hex）。
     */
    public function getCode(string $address, string|int $block = 'latest'): string
    {
        return (string) $this->rpc->request('eth_getCode', [$address, self::blockParam($block)]);
    }

    public function getStorageAt(string $address, string|int $slot, string|int $block = 'latest'): string
    {
        $slotHex = is_int($slot) || preg_match('/^\d+$/', (string) $slot)
            ? Hex::decToHex((string) $slot)
            : (string) $slot;

        return (string) $this->rpc->request('eth_getStorageAt', [$address, $slotHex, self::blockParam($block)]);
    }

    /**
     * 只读合约调用，返回原始 0x hex（配合 Contract 使用可自动解码）。
     *
     * @param array{to:string,data:string,from?:string} $call
     */
    public function call(array $call, string|int $block = 'latest'): string
    {
        $result = $this->rpc->request('eth_call', [$call, self::blockParam($block)]);

        return (string) ($result ?? '0x');
    }

    /**
     * 估算 gas（wei，十进制字符串）。
     *
     * 与其他方法不同：模拟执行被 revert（余额不足、授权不够等业务错误）
     * 不抛异常，而是直接返回链上报错文本（如 "Error(string): ERC20: transfer
     * amount exceeds balance"），用 ctype_digit() 区分估值与报错；
     * 只有节点完全不可达（传输层失败）才抛 RpcException。
     *
     * @param array{from?:string,to?:string,data?:string,value?:string} $call
     */
    public function estimateGas(array $call, string|int $block = 'latest'): string
    {
        try {
            return self::quantityToString($this->rpc->request('eth_estimateGas', [$call, self::blockParam($block)]));
        } catch (RpcException $e) {
            if ($e->rpcCode === 0) {
                throw $e;
            }

            return Decoder::decodeKnownError($e->rpcData) ?? $e->getMessage();
        }
    }

    /**
     * 广播已签名交易。链上拒绝（nonce too low、insufficient funds、already known、
     * 执行 revert 等）不抛异常，返回 ['ok' => false, 'error' => 报错文本]
     * （revert 原因自动解码）；只有节点完全不可达才抛 RpcException。
     *
     * @return array{ok:bool, hash:?string, error:?string}
     */
    public function sendRawTransaction(string $signedTransaction): array
    {
        try {
            $hash = (string) $this->rpc->request('eth_sendRawTransaction', [Hex::ensurePrefix($signedTransaction)]);
        } catch (RpcException $e) {
            if ($e->rpcCode === 0) {
                throw $e;
            }

            return ['ok' => false, 'hash' => null, 'error' => Decoder::decodeKnownError($e->rpcData) ?? $e->getMessage()];
        }

        return ['ok' => true, 'hash' => $hash, 'error' => null];
    }

    /**
     * 交易回执；未上链（pending / 不存在）返回 null——这不是错误。
     */
    public function getTransactionReceipt(string $hash): ?object
    {
        $result = $this->rpc->request('eth_getTransactionReceipt', [$hash]);

        return $result === null ? null : (object) $result;
    }

    public function getTransactionByHash(string $hash): ?object
    {
        $result = $this->rpc->request('eth_getTransactionByHash', [$hash]);

        return $result === null ? null : (object) $result;
    }

    public function getBlockByNumber(string|int $block = 'latest', bool $fullTransactions = true): ?object
    {
        $result = $this->rpc->request('eth_getBlockByNumber', [self::blockParam($block), $fullTransactions]);

        return $result === null ? null : (object) $result;
    }

    public function getBlockByHash(string $hash, bool $fullTransactions = true): ?object
    {
        $result = $this->rpc->request('eth_getBlockByHash', [$hash, $fullTransactions]);

        return $result === null ? null : (object) $result;
    }

    public function getBlockTransactionCountByNumber(string|int $block): int
    {
        return self::quantityToInt($this->rpc->request('eth_getBlockTransactionCountByNumber', [self::blockParam($block)]));
    }

    public function getBlockTransactionCountByHash(string $hash): int
    {
        return self::quantityToInt($this->rpc->request('eth_getBlockTransactionCountByHash', [$hash]));
    }

    /**
     * 事件日志查询（Transfer 监听等）。
     *
     * @param array{fromBlock?:string|int,toBlock?:string|int,address?:string|array<string>,topics?:array<null|string|array<string>>} $filter
     * @return array<int,object>
     */
    public function getLogs(array $filter): array
    {
        $params = [];
        if (isset($filter['fromBlock'])) {
            $params['fromBlock'] = self::blockParam($filter['fromBlock']);
        }
        if (isset($filter['toBlock'])) {
            $params['toBlock'] = self::blockParam($filter['toBlock']);
        }
        if (isset($filter['address'])) {
            $params['address'] = $filter['address'];
        }
        if (isset($filter['topics'])) {
            $params['topics'] = $filter['topics'];
        }

        $result = $this->rpc->request('eth_getLogs', [$params]);

        return array_map(fn ($log) => (object) $log, (array) $result);
    }

    /**
     * 轮询等待回执。超时返回 null（交易不等于失败，可能只是没打包），
     * 上链后 status=0 由 isSuccessful() 判断。
     */
    public function waitForReceipt(string $hash, float $timeoutSeconds = 60.0, int $pollIntervalMs = 1500): ?object
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $receipt = $this->getTransactionReceipt($hash);
            if ($receipt !== null) {
                return $receipt;
            }
            usleep($pollIntervalMs * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * 回执是否成功（status == 0x1）。
     */
    public static function isSuccessful(object $receipt): bool
    {
        return ($receipt->status ?? null) === '0x1' || ($receipt->status ?? null) === 1;
    }

    // —— 参数与返回值归一化 ——————————————————————————————————————

    private static function blockParam(string|int $block): string
    {
        if (is_int($block)) {
            return Hex::decToHex((string) $block);
        }
        $block = trim($block);
        if (in_array($block, ['latest', 'earliest', 'pending', 'safe', 'finalized'], true)) {
            return $block;
        }
        if (preg_match('/^\d+$/', $block)) {
            return Hex::decToHex($block);
        }
        if (preg_match('/^0x[0-9a-fA-F]+$/', $block)) {
            return $block;
        }

        throw new RpcException('非法区块参数：' . $block);
    }

    private static function quantityToInt(mixed $hex): int
    {
        if ($hex === null) {
            throw new RpcException('节点返回了 null（quantity 预期 hex）');
        }

        return gmp_intval(gmp_init(Hex::stripPrefix((string) $hex) ?: '0', 16));
    }

    private static function quantityToString(mixed $hex): string
    {
        if ($hex === null) {
            throw new RpcException('节点返回了 null（quantity 预期 hex）');
        }

        return Hex::toDec((string) $hex);
    }
}
