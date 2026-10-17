<?php

declare(strict_types=1);

namespace Web3php\Tx;

use Web3php\Exception\TxException;
use Web3php\Utils\Address;
use Web3php\Utils\Hex;
use Web3php\Wallet;

/**
 * 交易构建 + 本地签名。字段接受十进制字符串 / 0x hex / int（大数推荐字符串）。
 *
 * Legacy（默认）：nonce、gasPrice、gas、to、value、data、chainId
 * EIP-1559：      nonce、maxFeePerGas、maxPriorityFeePerGas、gas、to、value、data、chainId
 */
final class Transaction
{
    public static function sign(Wallet $wallet, array $fields): string
    {
        $tx = [
            'nonce' => Hex::toGmp(self::requireField($fields, 'nonce')),
            'gas' => Hex::toGmp(self::requireField($fields, 'gas')),
            'to' => self::toBin($fields['to'] ?? ''),
            'value' => Hex::toGmp((string) ($fields['value'] ?? '0')),
            'data' => Hex::toBin(Hex::stripPrefix((string) ($fields['data'] ?? '0x'))),
            'chainId' => (int) ($fields['chainId'] ?? 0),
        ];
        if ($tx['chainId'] <= 0) {
            throw new TxException('缺少 chainId（EIP-155 防重放必需）');
        }

        if (isset($fields['maxFeePerGas'])) {
            $tx['maxFeePerGas'] = Hex::toGmp((string) $fields['maxFeePerGas']);
            if (!isset($fields['maxPriorityFeePerGas'])) {
                throw new TxException('EIP-1559 模式需要同时提供 maxPriorityFeePerGas');
            }
            $tx['maxPriorityFeePerGas'] = Hex::toGmp((string) $fields['maxPriorityFeePerGas']);

            return Signer::signEip1559($tx, $wallet);
        }

        $tx['gasPrice'] = Hex::toGmp((string) self::requireField($fields, 'gasPrice'));

        return Signer::signLegacy($tx, $wallet);
    }

    private static function requireField(array $fields, string $name): string
    {
        if (!isset($fields[$name]) || $fields[$name] === '') {
            throw new TxException("交易缺少字段 {$name}");
        }

        return (string) $fields[$name];
    }

    private static function toBin(mixed $to): string
    {
        if ($to === null || $to === '') {
            return '';
        }
        if (!is_string($to) || !Address::isValid($to)) {
            throw new TxException('to 必须是 0x + 40 位 hex 地址（合约创建交易传空串）');
        }
        $bin = Hex::toBin(strtolower(Hex::stripPrefix($to)));
        if (strlen($bin) !== 20) {
            throw new TxException('to 地址必须为 20 字节');
        }

        return $bin;
    }
}
