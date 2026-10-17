<?php

declare(strict_types=1);

namespace Web3php\Utils;

use kornrunner\Keccak;

/**
 * 密码学工具：Keccak-256（Ethereum 版本）与函数选择子。
 */
final class Crypto
{
    /**
     * 对二进制串做 Keccak-256，返回 0x 前缀 hex。
     */
    public static function keccakBin(string $binary): string
    {
        return '0x' . Keccak::hash($binary, 256);
    }

    /**
     * 对 hex 数据（0x 可选）做 Keccak-256，返回 0x 前缀 hex。
     */
    public static function keccakHex(string $hex): string
    {
        return self::keccakBin(Hex::toBin($hex));
    }

    /**
     * 规范签名（如 transfer(address,uint256)）→ 4 字节选择子（0x + 8 hex）。
     */
    public static function selector(string $canonicalSignature): string
    {
        return '0x' . substr(Hex::stripPrefix(self::keccakBin($canonicalSignature)), 0, 8);
    }
}
