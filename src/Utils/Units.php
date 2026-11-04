<?php

declare(strict_types=1);

namespace Web3php\Utils;

use Web3php\Exception\UnitsException;

/**
 * 单位与精度转换工具（web3.utils.fromWei / toWei 的 PHP 实现）。
 *
 * 全部基于 GMP 大整数运算，uint256 全程无精度丢失。
 * 设计上刻意拒绝浮点数：0.1 这类十进制小数无法被二进制浮点精确表示，
 * 在 wei 量级（10^18）下浮点误差足以造成资产数额错误。
 *
 * 常见单位：
 *   wei=0, kwei=3, mwei=6, gwei=9, szabo=12, finney=15, ether=18,
 *   kether=21, mether=24, gether=27, tether=30
 */
final class Units
{
    /** 内置单位名 → 小数位数（18 位精度以下） */
    private const UNITS = [
        'wei'    => 0,
        'kwei'   => 3,   // babbage
        'mwei'   => 6,   // lovelace
        'gwei'   => 9,   // shannon
        'szabo'  => 12,
        'finney' => 15,
        'ether'  => 18,
        'kether' => 21,  // grand
        'mether' => 24,
        'gether' => 27,
        'tether' => 30,
    ];

    /** 单位别名（统一小写）→ 规范名 */
    private const ALIASES = [
        'babbage' => 'kwei',
        'lovelace' => 'mwei',
        'shannon' => 'gwei',
        'grand' => 'kether',
    ];

    /**
     * wei → 目标单位的十进制字符串。
     *
     *   Units::fromWei('1234567890123456789', 'ether')  => '1.234567890123456789'
     *   Units::fromWei('1000000000000000000', 'ether')  => '1'
     *   Units::fromWei('1500000000000000000', 'ether')  => '1.5'
     *
     * 小数末尾的 0 会被去除；整数部分与 wei 之间不足位数用 0 前导补齐。
     * $decimals 也可直接传数字，如 ERC-20 的 6（USDT）/ 8（BTC 常见）。
     *
     * @param int|string|\GMP $wei    wei 数量（或任意最小单位的大整数）
     * @param string|int      $unit   单位名（wei/kwei/gwei/ether...）或自定义小数位数
     * @return string                 十进制字符串（不含千分位）
     */
    public static function fromWei(int|string|\GMP $wei, string|int $unit = 'ether'): string
    {
        $decimals = self::resolveDecimals($unit);
        $gmp = Hex::toGmp($wei);
        $negative = gmp_sign($gmp) < 0;
        $value = gmp_strval(gmp_abs($gmp));

        if ($decimals === 0) {
            return $negative ? '-' . $value : $value;
        }

        if (strlen($value) <= $decimals) {
            // 小于 1 个目标单位：如 999 wei → 0.000000000000000999
            $frac = str_pad($value, $decimals, '0', STR_PAD_LEFT);
            $result = '0.' . rtrim($frac, '0');
            if ($result === '0.') {
                $result = '0';
            }
        } else {
            $intPart = substr($value, 0, -$decimals);
            $fracPart = rtrim(substr($value, -$decimals), '0');
            $result = $fracPart === '' ? $intPart : $intPart . '.' . $fracPart;
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * 十进制数量 → wei（最小单位）字符串。
     *
     *   Units::toWei('1.5', 'ether')            => '1500000000000000000'
     *   Units::toWei('0.000000001', 'ether')    => '1'（1 gwei）
     *   Units::toWei('1', 'gwei')               => '1000000000'
     *   Units::toWei('1.2345', 2)               => '123'（超出 2 位精度，默认直接抛错）
     *
     * 小数位数超过目标精度时的行为由 $mode 控制：
     *   - Units::ROUND_REJECT（默认）：抛出 UnitsException，防止静默丢钱
     *   - Units::ROUND_DOWN：向下截断（1.999 gwei → 1999999999 wei）
     *   - Units::ROUND_UP：向上进位
     *
     * @param string|int|\GMP $amount 十进制数量字符串（允许负号）；不推荐传 int，
     *                                但为兼容小数值仍接受（内部转字符串，float 会被拒绝）
     * @param string|int      $unit   单位名或自定义小数位数
     * @return string                 wei 十进制字符串
     */
    public static function toWei(string|int|\GMP $amount, string|int $unit = 'ether', int $mode = self::ROUND_REJECT): string
    {
        if (is_float($amount)) {
            throw new UnitsException('浮点数有精度丢失风险，请使用字符串，如 toWei("1.5", "ether")');
        }

        $decimals = self::resolveDecimals($unit);
        $s = is_string($amount) ? trim($amount) : gmp_strval(Hex::toGmp($amount));

        if (!preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new UnitsException('无法解析的十进制数量：' . $s);
        }

        $negative = $m[1] === '-';
        $intPart = $m[2] === '' ? '0' : $m[2];
        $fracPart = $m[3] ?? '';

        if (strlen($fracPart) > $decimals) {
            [$intPart, $fracPart] = self::roundFraction($intPart, $fracPart, $decimals, $mode);
        }

        $paddedFrac = str_pad($fracPart, $decimals, '0', STR_PAD_RIGHT);
        $value = gmp_init(($negative ? '-' : '') . $intPart . $paddedFrac, 10);

        return gmp_strval($value);
    }

    /** 向下截断模式 */
    public const ROUND_DOWN = 1;
    /** 向上进位模式 */
    public const ROUND_UP = 2;
    /** 超出精度即抛错（默认，最安全） */
    public const ROUND_REJECT = 3;

    /**
     * 处理小数位数超限：按模式截断/进位，并可能向整数部分进位。
     *
     * @return array{0:string,1:string} [调整后的整数部分, 调整后（≤$decimals 位）的小数部分]
     */
    private static function roundFraction(string $intPart, string $fracPart, int $decimals, int $mode): array
    {
        if ($mode === self::ROUND_REJECT) {
            throw new UnitsException(sprintf(
                '小数位数超出目标精度（%d 位 > %d 位）：.%s。如需截断请显式传 Units::ROUND_DOWN / Units::ROUND_UP',
                strlen($fracPart), $decimals, $fracPart
            ));
        }

        $kept = substr($fracPart, 0, $decimals);
        $rest = substr($fracPart, $decimals);

        if ($mode === self::ROUND_UP && $rest !== '' && ltrim($rest, '0') !== '') {
            // 对保留部分 +1（沿整数方向进位）
            $origLen = strlen($intPart);
            $combined = gmp_strval(gmp_add(gmp_init($intPart . $kept, 10), gmp_init(1)));
            if (substr($combined, 0, $origLen) !== $intPart) {
                // 进位冲到整数部分（长度增加或整数位本身变化）
                $intPart = substr($combined, 0, $origLen);
            }
            $kept = substr($combined, $origLen);
        }

        return [$intPart, $kept];
    }

    /**
     * 通用双向转换的便捷入口（同 web3.utils）：
     *   Units::convert('1.5', 'ether', 'wei')   => '1500000000000000000'
     *   Units::convert('1000000000000000000', 'wei', 'ether') => '1'
     * $mode 仅在缩小单位（如 ether → gwei）且小数超限时生效。
     */
    public static function convert(string|int|\GMP $amount, string|int $fromUnit, string|int $toUnit, int $mode = self::ROUND_REJECT): string
    {
        $wei = self::toWei($amount, $fromUnit, $mode);

        return self::fromWei($wei, $toUnit);
    }

    /**
     * 解析单位名或小数位数 → 小数位数。
     *
     * @param string|int $unit 'ether'、'gwei'、别名（'shannon'）或 int（如 6、18）
     */
    public static function resolveDecimals(string|int $unit): int
    {
        if (is_int($unit)) {
            if ($unit < 0 || $unit > 255) {
                throw new UnitsException('非法小数位数：' . $unit . '（范围 0-255）');
            }

            return $unit;
        }

        $name = strtolower(trim($unit));
        $name = self::ALIASES[$name] ?? $name;

        if (!isset(self::UNITS[$name])) {
            throw new UnitsException(sprintf(
                '未知单位：%s（支持：%s，或直接传小数位数 int）',
                $unit,
                implode(', ', array_keys(self::UNITS))
            ));
        }

        return self::UNITS[$name];
    }

    /**
     * 常见 ERC-20 快捷转换：最小单位 → 人类可读数量（代币 decimals）。
     *
     *   Units::tokenFromBase('123456', 6)  => '0.123456'（USDT）
     */
    public static function tokenFromBase(int|string|\GMP $baseAmount, int $decimals): string
    {
        return self::fromWei($baseAmount, $decimals);
    }

    /**
     * 人类可读数量 → ERC-20 最小单位（用于构造 calldata 的 amount）。
     *
     *   Units::tokenToBase('0.123456', 6)  => '123456'
     */
    public static function tokenToBase(string|int|\GMP $amount, int $decimals, int $mode = self::ROUND_REJECT): string
    {
        return self::toWei($amount, $decimals, $mode);
    }
}
