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
 * $erc20->call('getReserves');              // 多输出全命名 → ['reserve0' => …, 'reserve1' => …]
 * $erc20->send('transfer', [$to, '1000000'], $wallet);
 * $erc20->decodeInput($tx->input);          // 解析交易 calldata
 * $erc20->decodeLogs($receipt->logs);       // 解析事件日志
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
     * 单输出返回标量；多输出在全命名时只返回字段名键（有未命名输出则保留数字键兜底）。
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
     * 解析交易的 input data（calldata）：按前 4 字节选择子反查 ABI 方法并解码参数。
     * input 为 '0x'（纯主币转账）时返回 method=null，不报错。
     *
     * @return array{method:?string, signature:?string, selector:?string, args:array<int|string,mixed>}
     */
    public function decodeInput(string $input): array
    {
        $hex = Hex::stripPrefix($input);
        if ($hex === '') {
            return ['method' => null, 'signature' => null, 'selector' => null, 'args' => []];
        }
        if (strlen($hex) < 8 || !ctype_xdigit($hex)) {
            throw new AbiException('非法的 calldata（hex，选择子 + 参数）：' . substr($input, 0, 74));
        }

        $selector = substr($hex, 0, 8);
        $method = $this->abi->bySelector($selector)
            ?? throw new AbiException('选择子 0x' . strtolower($selector) . ' 不在 ABI 中，无法精确解析；无 ABI 时可用 RawDecoder 尽力识别');

        return [
            'method' => $method->name,
            'signature' => $method->signature,
            'selector' => '0x' . strtolower($selector),
            'args' => Decoder::decode($method->inputs, '0x' . substr($hex, 8)),
        ];
    }

    /**
     * 解析回执（或 getLogs）的日志数组：按 topic0 反查 ABI 事件并解码参数。
     * 不在 ABI 中的日志不报错，event=null 并把原始日志放在 log 键下。
     *
     * @param array<int,object|array> $logs
     * @return array<int,array{event:?string, address:?string, args:array<int|string,mixed>, log:object}>
     */
    public function decodeLogs(array $logs): array
    {
        $out = [];
        foreach ($logs as $log) {
            $log = (object) $log;
            $topics = array_map(strval(...), (array) ($log->topics ?? []));

            $event = isset($topics[0]) ? $this->abi->byTopic0($topics[0]) : null;
            $out[] = [
                'event' => $event?->name,
                'address' => isset($log->address) ? (string) $log->address : null,
                'args' => $event !== null
                    ? Decoder::decodeEvent($event, $topics, (string) ($log->data ?? '0x'))
                    : [],
                'log' => $log,
            ];
        }

        return $out;
    }

    /**
     * 估算某方法调用的 gas（wei，十进制字符串）。
     *
     * 模拟执行被 revert 时不抛异常，直接返回链上报错文本
     * （如 "Error(string): transfer amount exceeds balance"），用 ctype_digit() 区分估值与报错。
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
     * 发送交易（本地签名 + 广播），返回 ['ok'/'hash'/'error']：
     * 链上拒绝（nonce too low、余额不足、revert 等）不抛异常，报错在 error 键
     * （revert 原因自动解码）；只有节点不可达才抛 RpcException。
     * $options 可覆盖：nonce、gas、gasPrice（或 maxFeePerGas+maxPriorityFeePerGas）、
     * value（payable 金额）、chainId。
     *
     * @return array{ok:bool, hash:?string, error:?string}
     */
    public function send(string $method, array $args, Wallet|string $wallet, array $options = []): array
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
