<?php

declare(strict_types=1);

namespace Web3php\Utils;

use Web3php\Exception\AbiException;

/**
 * 地址工具：EIP-55 混合大小写校验和。
 */
final class Address
{
    /**
     * 任意大小写地址 → EIP-55 校验和地址。
     */
    public static function toChecksum(string $address): string
    {
        $lower = strtolower(Hex::stripPrefix($address));
        if (strlen($lower) !== 40 || !preg_match('/^[0-9a-f]+$/', $lower)) {
            throw new AbiException('非法地址（需要 20 字节 hex）：' . $address);
        }

        // EIP-55：哈希对象是 40 位小写 hex 的 ASCII 文本，不是二进制
        $hash = Crypto::keccakBin($lower);
        $hashHex = Hex::stripPrefix($hash);

        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $char = $lower[$i];
            if ($char >= 'a' && $char <= 'f' && (int) hexdec($hashHex[$i]) >= 8) {
                $out .= strtoupper($char);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * 基本格式校验（仅校验 40 位 hex，不校验大小写）。
     */
    public static function isValid(string $address): bool
    {
        $body = Hex::stripPrefix($address);

        return strlen($body) === 40 && (bool) preg_match('/^[0-9a-fA-F]+$/', $body);
    }
}
