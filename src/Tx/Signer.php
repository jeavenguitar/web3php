<?php

declare(strict_types=1);

namespace Web3php\Tx;

use Web3php\Utils\Crypto;
use Web3php\Utils\Hex;
use Web3php\Wallet;

/**
 * 交易本地签名（无需节点 personal_unlockAccount，公网节点友好）：
 *   - Legacy + EIP-155（chainId 防重放）
 *   - EIP-1559（type 2，动态费用）
 *
 * 签名后 r/s 按 RLP 整数规范做最小编码（去掉前导 0x00 字节，geth 拒绝非规范整数）；
 * canonical 模式保证 low-s（EIP-2 共识要求）。
 */
final class Signer
{
    /**
     * Legacy EIP-155。字段均为已归一化的形式：
     * nonce/gasPrice/gas/value: GMP；to/data: 二进制串；chainId: int。
     */
    public static function signLegacy(array $tx, Wallet $wallet): string
    {
        $chainId = (int) $tx['chainId'];
        $base = [$tx['nonce'], $tx['gasPrice'], $tx['gas'], $tx['to'], $tx['value'], $tx['data']];

        $unsigned = $chainId > 0 ? [...$base, $chainId, '', ''] : $base;
        $hash = Crypto::keccakBin(Rlp::encode($unsigned));

        [$r, $s, $recovery] = self::ecSign($wallet, $hash);

        $v = $chainId > 0 ? 35 + $recovery + 2 * $chainId : 27 + $recovery;

        $signed = [...$base, $v, $r, $s];

        return '0x' . bin2hex(Rlp::encode($signed));
    }

    /**
     * EIP-1559（type 2）。$tx 需含 chainId/nonce/maxPriorityFeePerGas/maxFeePerGas/gas/to/value/data，
     * accessList 固定为空列表。
     */
    public static function signEip1559(array $tx, Wallet $wallet): string
    {
        $base = [
            $tx['chainId'],
            $tx['nonce'],
            $tx['maxPriorityFeePerGas'],
            $tx['maxFeePerGas'],
            $tx['gas'],
            $tx['to'],
            $tx['value'],
            $tx['data'],
            [],
        ];

        $hash = Crypto::keccakBin("\x02" . Rlp::encode($base));

        [$r, $s, $recovery] = self::ecSign($wallet, $hash);

        $signed = [...$base, $recovery, $r, $s];

        return '0x02' . bin2hex(Rlp::encode($signed));
    }

    /**
     * secp256k1 签名 32 字节哈希。
     *
     * @return array{0:string,1:string,2:int} [r(二进制,最小编码), s(二进制,最小编码), recoveryParam]
     */
    private static function ecSign(Wallet $wallet, string $hashHex): array
    {
        // elliptic 的 BN(msg, 16) 只认纯 hex，不能带 0x 前缀
        $signature = $wallet->keyPair()->sign(Hex::stripPrefix($hashHex), ['canonical' => true]);
        $r = $signature->r->toString(16);
        $s = $signature->s->toString(16);
        if (strlen($r) % 2 === 1) {
            $r = '0' . $r;
        }
        if (strlen($s) % 2 === 1) {
            $s = '0' . $s;
        }

        // RLP 整数必须最小编码：去掉前导 0x00 字节，否则 geth 报
        // "rlp: non-canonical integer (leading zero bytes) for *big.Int"（公网节点常表现为 unmarshal transaction failed）
        return [ltrim(hex2bin($r), "\x00"), ltrim(hex2bin($s), "\x00"), (int) $signature->recoveryParam];
    }
}
