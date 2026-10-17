<?php

declare(strict_types=1);

namespace Web3php\Utils;

use Web3php\Exception\AbiException;

/**
 * 十六进制工具：统一处理 0x 前缀、数量（quantity）与数据（data）两类 hex、
 * 以及基于 GMP 的安全大整数转换（uint256 精度不能丢）。
 */
final class Hex
{
    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^(0x|0X)?[0-9a-fA-F]+$/', $value);
    }

    public static function stripPrefix(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }

        return $hex;
    }

    public static function ensurePrefix(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? $hex : '0x' . $hex;
    }

    /**
     * hex → 十进制字符串（GMP，任意精度），空串视为 0。
     */
    public static function toDec(string $hex): string
    {
        $hex = self::stripPrefix($hex);
        if ($hex === '') {
            return '0';
        }

        return gmp_strval(gmp_init($hex, 16), 10);
    }

    /**
     * 十进制（int / 十进制字符串 / GMP / 0x hex 字符串）→ 0x 前缀 hex。
     * $padBytes > 0 时左侧补齐到固定字节数；否则输出最小数量形式（"0x0"、"0x1f"…）。
     */
    public static function decToHex(string|int|\GMP $value, int $padBytes = 0): string
    {
        $n = self::toGmp($value);
        $hex = gmp_strval($n, 16);

        if ($padBytes > 0) {
            return '0x' . str_pad($hex, $padBytes * 2, '0', STR_PAD_LEFT);
        }

        return '0x' . $hex;
    }

    /**
     * hex 字符串 → 二进制串；奇数长度直接抛错（避免静默歧义）。
     */
    public static function toBin(string $hex): string
    {
        $hex = self::stripPrefix($hex);
        if ($hex === '') {
            return '';
        }
        if (!preg_match('/^[0-9a-fA-F]+$/', $hex) || strlen($hex) % 2 === 1) {
            throw new AbiException('非法的十六进制数据（必须为偶数长度的 hex 字符）：' . substr($hex, 0, 64));
        }

        return hex2bin($hex);
    }

    public static function fromBin(string $binary): string
    {
        return '0x' . bin2hex($binary);
    }

    /**
     * 左侧补齐到 32 字节（64 个 hex 字符），用于 ABI 字。
     */
    public static function padLeft32(string $hex): string
    {
        $hex = self::stripPrefix($hex);

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * 右侧补齐到 32 字节整数倍（hex 字符数），用于动态 bytes/string。
     */
    public static function padRight32(string $hex): string
    {
        $hex = self::stripPrefix($hex);

        return str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);
    }

    /**
     * 数值 → GMP。接受 int、十进制字符串、0x hex 字符串、GMP。
     * 浮点数直接拒绝——uint256 范围内浮点必然丢精度，是事故源头。
     */
    public static function toGmp(mixed $value): \GMP
    {
        if ($value instanceof \GMP) {
            return $value;
        }
        if (is_int($value)) {
            return gmp_init($value);
        }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') {
                throw new AbiException('数值不能为空字符串');
            }
            try {
                if (str_starts_with(strtolower($s), '0x')) {
                    $body = substr($s, 2);

                    return $body === '' ? gmp_init(0) : gmp_init($body, 16);
                }

                return gmp_init($s, 10);
            } catch (\ValueError $e) {
                throw new AbiException('无法解析为整数：' . $s, 0, $e);
            }
        }
        if (is_bool($value)) {
            throw new AbiException('布尔值不能作为数值传入（请传 int 或字符串）');
        }

        throw new AbiException('不支持的数值类型：' . get_debug_type($value) . '（浮点数有精度丢失风险，请使用字符串）');
    }
}
