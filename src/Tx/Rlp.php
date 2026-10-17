<?php

declare(strict_types=1);

namespace Web3php\Tx;

use Web3php\Exception\TxException;

/**
 * RLP 编码（以太坊交易序列化）。
 *
 * 入参约定（刻意消除歧义）：
 *   - string  → 二进制数据（地址、calldata、签名 r/s 均先 hex2bin）
 *   - int|GMP → 数量（quantity），0 编码为空串
 *   - array   → 列表，递归编码
 */
final class Rlp
{
    public static function encode(string|int|\GMP|array $item): string
    {
        if (is_array($item)) {
            $payload = '';
            foreach ($item as $sub) {
                $payload .= self::encode($sub);
            }

            return self::encodeLength(strlen($payload), 0xc0) . $payload;
        }

        $binary = is_string($item) ? $item : self::intToBin($item instanceof \GMP ? $item : gmp_init($item));
        $length = strlen($binary);

        // 单字节且 < 0x80 自身即编码
        if ($length === 1 && ord($binary) < 0x80) {
            return $binary;
        }

        return self::encodeLength($length, 0x80) . $binary;
    }

    private static function encodeLength(int $length, int $offset): string
    {
        if ($length < 56) {
            return chr($length + $offset);
        }

        $lengthBin = self::intToBin(gmp_init($length));

        return chr($offset + 55 + strlen($lengthBin)) . $lengthBin;
    }

    private static function intToBin(\GMP $n): string
    {
        if (gmp_sign($n) < 0) {
            throw new TxException('RLP 数量不能为负');
        }
        $hex = gmp_strval($n, 16);
        if ($hex === '0') {
            // 整数 0 的 RLP 编码是空串（再包一层 0x80）
            return '';
        }
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }

        return hex2bin($hex);
    }
}
