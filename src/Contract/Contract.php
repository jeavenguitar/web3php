<?php

declare(strict_types=1);

namespace Web3php\Contract;

use Web3php\Abi\Abi;
use Web3php\Abi\Decoder;
use Web3php\Abi\Encoder;
use Web3php\Abi\Method;
use Web3php\Abi\ParamType;
use Web3php\Exception\AbiException;
use Web3php\Exception\RpcException;
use Web3php\Utils\Address;
use Web3php\Utils\Hex;
use Web3php\Wallet;
use Web3php\Web3;

/**
 * 合约对象：引入 ABI 后按方法名调用，查询自动解码返回值，发送自动签名。
 *
 * $erc20 = $web3->contract($abiJson)->at('0x...');
 * $erc20->call('name');                     // "Token"（单输出直接是标量）
 * $erc20->call('balanceOf', $user);         // "1234500000000000000"（十进制字符串）
 * $erc20->call('getReserves');              // 多输出 → [0 => …, 1 => …, 'reserve0' => …, …]
 * $erc20->send('transfer', [$to, '1000000'], $wallet);
 */
final class Contract
{
    private string $address;

    public function __construct(
        private readonly Web3 $web3,
        private readonly Abi $abi,
        string $address,
    ) {
        if (!Address::isValid($address)) {
            throw new AbiException('合约地址非法：' . $address);
        }
        $this->address = Address::toChecksum($address);
    }

    public function abi(): Abi
    {
        return $this->abi;
    }

    public function address(): string
    {
        return $this->address;
    }

    /**
     * 绑定合约地址（链式）：$web3->contract($abi)->at('0x...')。
     */
    public function at(string $address): self
    {
        return new self($this->web3, $this->abi, $address);
    }

    /**
     * 只读调用（eth_call）。参数直接展开传，按 ABI 输出自动解码：
     * 单输出返回标量，多输出返回"数字键 + 字段名键"的数组。
     */
    public function call(string $method, mixed ...$args): mixed
    {
        return $this->callAt($method, array_values($args));
    }

    /**
     * 同 call()，但支持选项：block（区块标签/号）、from（模拟调用方）。
     */
    public function callAt(string $method, array $args = [], array $options = []): mixed
    {
        $definition = $this->abi->method($method, count($args));
        $data = $this->encodeData($definition, $args);

        $call = ['to' => $this->address, 'data' => $data];
        if (!empty($options['from'])) {
            $call['from'] = $options['from'];
        }

        try {
            $raw = $this->web3->eth()->call($call, $options['block'] ?? 'latest');
        } catch (RpcException $e) {
            $reason = Decoder::decodeKnownError($e->rpcData);
            throw new RpcException(
                $e->getMessage() . ($reason !== null ? '（revert 原因：' . $reason . '）' : ''),
                $e->rpcCode,
                $e->rpcData,
            );
        }

        $decoded = Decoder::decode($definition->outputs, $raw);

        // 单输出直接给标量；多输出保持数组（已含命名键）
        if ($definition->outputs === []) {
            return null;
        }

        return count($definition->outputs) === 1 ? $decoded[0] : $decoded;
    }

    /**
     * 编码 calldata（选择子 + 参数），0x 前缀。
     */
    public function encode(string $method, array $args = []): string
    {
        return $this->encodeData($this->abi->method($method, count($args)), $args);
    }

    /**
     * 把 eth_call 返回的 hex 按方法输出解码（不发起网络请求）。
     *
     * @return array<int|string,mixed>
     */
    public function decode(string $method, string $dataHex): array
    {
        return Decoder::decode($this->abi->method($method)->outputs, $dataHex);
    }

    /**
     * 估算某方法调用的 gas（wei，十进制字符串）。
     */
    public function estimateGas(string $method, array $args = [], ?string $from = null, array $options = []): string
    {
        $definition = $this->abi->method($method, count($args));
        $call = [
            'to' => $this->address,
            'data' => $this->encodeData($definition, $args),
        ];
        if ($from !== null) {
            $call['from'] = $from;
        }

        return $this->web3->eth()->estimateGas($call, $options['block'] ?? 'latest');
    }

    /**
     * 发送交易（本地签名 + 广播），返回交易哈希。
     * $options 可覆盖：nonce、gas、gasPrice（或 maxFeePerGas+maxPriorityFeePerGas）、
     * value（payable 金额）、chainId。
     */
    public function send(string $method, array $args, Wallet|string $wallet, array $options = []): string
    {
        $wallet = is_string($wallet) ? new Wallet($wallet) : $wallet;

        $data = $this->encodeData($this->abi->method($method, count($args)), $args);

        $tx = [
            'to' => $this->address,
            'data' => $data,
            'value' => (string) ($options['value'] ?? '0'),
        ];
        foreach (['nonce', 'gas', 'gasPrice', 'maxFeePerGas', 'maxPriorityFeePerGas', 'chainId'] as $field) {
            if (array_key_exists($field, $options)) {
                $tx[$field] = $options[$field];
            }
        }

        return $this->web3->sendTransaction($wallet, $tx);
    }

    private function encodeData(Method $definition, array $args): string
    {
        /** @var ParamType[] $inputTypes */
        $inputTypes = $definition->inputs;

        return '0x' . Hex::stripPrefix($definition->selector) . Encoder::encode($inputTypes, $args);
    }
}
