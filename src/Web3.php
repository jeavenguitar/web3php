<?php

declare(strict_types=1);

namespace Web3php;

use Web3php\Abi\Abi;
use Web3php\Abi\Decoder;
use Web3php\Contract\Contract;
use Web3php\Exception\RpcException;
use Web3php\Exception\TxException;
use Web3php\Rpc\RpcClient;
use Web3php\Tx\Transaction;

/**
 * 门面入口（web3.js 风格）：
 *
 *   $web3 = new Web3('https://bsc-dataseed.bnbchain.org');
 *   $web3 = new Web3(['https://节点A', 'https://节点B'], ['timeout' => 30]);
 *
 *   $web3->eth()->blockNumber();
 *   $erc20 = $web3->contract($abiJson)->at('0x...');
 *   $erc20->call('balanceOf', $address);
 *   $web3->sendTransaction($wallet, ['to' => …, 'value' => '1000000000000000000']);
 */
class Web3
{
    private readonly RpcClient $rpc;

    private ?Eth $eth = null;

    private ?int $chainId = null;

    /**
     * @param string|array $rpcEndpoints 单个或多个 RPC URL（推荐多个：故障自动转移）
     * @param array<string,mixed> $options 见 RpcClient::DEFAULT_OPTIONS
     */
    public function __construct(string|array $rpcEndpoints, array $options = [])
    {
        $this->rpc = new RpcClient($rpcEndpoints, $options);
    }

    public function rpc(): RpcClient
    {
        return $this->rpc;
    }

    public function eth(): Eth
    {
        return $this->eth ??= new Eth($this->rpc);
    }

    /**
     * chainId（懒加载 + 缓存；发交易防重放用）。
     */
    public function chainId(): int
    {
        return $this->chainId ??= $this->eth()->chainId();
    }

    /**
     * 实例化合约对象；地址可在 at() 时再给。
     */
    public function contract(string|array|Abi $abi, ?string $address = null): Contract
    {
        $abiObject = $abi instanceof Abi ? $abi : new Abi($abi);

        return new Contract($this, $abiObject, $address ?? '0x0000000000000000000000000000000000000000');
    }

    /**
     * 从私钥创建钱包。
     */
    public function wallet(string $privateKey): Wallet
    {
        return new Wallet($privateKey);
    }

    /**
     * 发送交易（自动补 nonce / gas / 费用 / chainId，本地签名后广播），返回交易哈希。
     *
     * $tx 最少只需要 to + value（转账）或 to + data（合约调用）：
     *   - nonce    缺省自动取 from 的 pending nonce
     *   - chainId  缺省自动取当前节点
     *   - gasPrice 缺省自动取 eth_gasPrice（Legacy 模式）
     *   - gas      缺省自动 estimateGas（revert 时抛 TxException 并尽量解出原因）
     * EIP-1559：显式给 maxFeePerGas（可再给 maxPriorityFeePerGas）即切换 type 2。
     */
    public function sendTransaction(Wallet $wallet, array $tx): string
    {
        $tx['from'] = $wallet->address();

        if (!isset($tx['nonce'])) {
            $tx['nonce'] = $this->eth()->getTransactionCount($tx['from'], 'pending');
        }
        if (empty($tx['chainId'])) {
            $tx['chainId'] = $this->chainId();
        }

        $is1559 = isset($tx['maxFeePerGas']);
        if ($is1559) {
            if (!isset($tx['maxPriorityFeePerGas'])) {
                try {
                    $tx['maxPriorityFeePerGas'] = $this->eth()->maxPriorityFeePerGas();
                } catch (RpcException) {
                    $tx['maxPriorityFeePerGas'] = $this->eth()->gasPrice();
                }
            }
        } elseif (!isset($tx['gasPrice'])) {
            $tx['gasPrice'] = $this->eth()->gasPrice();
        }

        if (empty($tx['gas'])) {
            $estimate = ['from' => $tx['from']];
            if (!empty($tx['to'])) {
                $estimate['to'] = $tx['to'];
            }
            if (!empty($tx['value'])) {
                $estimate['value'] = Hex::decToHex((string) $tx['value']);
            }
            if (!empty($tx['data'])) {
                $estimate['data'] = $tx['data'];
            }
            try {
                $tx['gas'] = $this->eth()->estimateGas($estimate);
            } catch (RpcException $e) {
                $reason = Decoder::decodeKnownError($e->rpcData);
                throw new TxException(
                    'estimateGas 失败，交易大概率会 revert：' . $e->getMessage()
                    . ($reason !== null ? '（原因：' . $reason . '）' : ''),
                    0,
                    $e,
                );
            }
        }

        $raw = Transaction::sign($wallet, $tx);

        try {
            return $this->eth()->sendRawTransaction($raw);
        } catch (RpcException $e) {
            $reason = Decoder::decodeKnownError($e->rpcData);
            if ($reason !== null) {
                throw new TxException('广播被拒：' . $e->getMessage() . '（原因：' . $reason . '）', 0, $e);
            }

            throw $e;
        }
    }
}
