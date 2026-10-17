<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Exception\AbiException;
use Web3php\Utils\Address;
use Web3php\Utils\Hex;

/**
 * Solidity ABI 编码器（完整 ABI v2：动态类型 offset 定位、tuple、嵌套数组）。
 *
 * 编码采用标准 head/tail 结构：encodeBlock() 负责一个"块"内所有成员的
 * 头部排列与 offset 解析；动态值（bytes/string/动态数组/动态 tuple）的头
 * 先占一个 64 字符占位字，随后替换为到尾部的真实偏移。
 */
final class Encoder
{
    private const PLACEHOLDER = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * 编码一组参数为完整 calldata body（不含选择子，无 0x 前缀）。
     *
     * @param ParamType[] $types
     * @param array<int|string,mixed> $values
     */
    public static function encode(array $types, array $values): string
    {
        $values = array_values($values);
        if (count($types) !== count($values)) {
            throw new AbiException('参数个数不匹配：期望 ' . count($types) . ' 个，实际 ' . count($values) . ' 个');
        }

        return self::encodeBlock($types, $values);
    }

    /**
     * 编码一个块（参数列表 / tuple 内容 / 数组元素区），返回完整 hex。
     *
     * @param ParamType[] $types
     * @param array<int,mixed> $values
     */
    private static function encodeBlock(array $types, array $values): string
    {
        $heads = [];
        $tails = [];

        foreach ($types as $i => $type) {
            [$head, $tail] = self::encodeValue($type, $values[$i]);
            $heads[] = $head;
            $tails[] = $tail;
        }

        // 所有头部先定长（动态值占位字也是 64 字符），偏移才能确定；
        // 偏移单位是字节（hex 字符数 / 2）
        $headLength = intdiv(array_sum(array_map('strlen', $heads)), 2);
        $offset = $headLength;
        foreach ($types as $i => $type) {
            if ($tails[$i] !== '') {
                $heads[$i] = self::wordOf(Hex::toGmp((string) $offset));
                $offset += intdiv(strlen($tails[$i]), 2);
            }
        }

        return implode('', $heads) . implode('', $tails);
    }

    /**
     * 编码单个值，返回 [head, tail]（均为无 0x 的 hex）。
     * 动态类型 head 为 64 字符占位字，由 encodeBlock 替换为真实偏移。
     *
     * @return array{0:string,1:string}
     */
    private static function encodeValue(ParamType $type, mixed $value): array
    {
        switch ($type->base) {
            case 'uint':
                return [self::wordUint($type, $value), ''];

            case 'int':
                return [self::wordInt($type, $value), ''];

            case 'bool':
                return [self::wordOf(self::toBool($value) ? 1 : 0), ''];

            case 'address':
                return [Hex::padLeft32(self::addressHex($value)), ''];

            case 'bytes':
                if ($type->size === null) {
                    $hex = self::bytesHex($value);

                    return [self::PLACEHOLDER, self::wordOf(Hex::toGmp((string) (strlen($hex) / 2))) . Hex::padRight32($hex)];
                }

                return [self::wordFixedBytes($type->size, $value), ''];

            case 'string':
                if (!is_string($value)) {
                    throw new AbiException('string 参数必须是字符串，收到 ' . get_debug_type($value));
                }
                $hex = bin2hex($value);

                return [self::PLACEHOLDER, self::wordOf(Hex::toGmp((string) (strlen($hex) / 2))) . Hex::padRight32($hex)];

            case 'tuple':
                $encoding = self::encodeBlock($type->components, self::tupleValues($type, $value));

                return $type->isDynamic() ? [self::PLACEHOLDER, $encoding] : [$encoding, ''];

            case 'array':
                $items = self::arrayItems($type, $value);
                $elementTypes = array_fill(0, count($items), $type->element);
                // 数组元素区的偏移以元素区起始为基准（动态数组为长度字之后）
                $block = self::encodeBlock($elementTypes, $items);
                $encoding = $type->size === null
                    ? self::wordOf(Hex::toGmp((string) count($items))) . $block
                    : $block;

                return $type->isDynamic() ? [self::PLACEHOLDER, $encoding] : [$encoding, ''];
        }

        throw new AbiException('无法编码的类型基类：' . $type->base);
    }

    // —— 各类型取值与校验 ——————————————————————————————————————————

    private static function wordUint(ParamType $type, mixed $value): string
    {
        $n = Hex::toGmp($value);
        $max = gmp_sub(gmp_pow(2, $type->size), 1);
        if (gmp_sign($n) < 0 || gmp_cmp($n, $max) > 0) {
            throw new AbiException("uint{$type->size} 数值越界：{$value}");
        }

        return self::wordOf($n);
    }

    private static function wordInt(ParamType $type, mixed $value): string
    {
        $n = Hex::toGmp($value);
        $min = gmp_neg(gmp_pow(2, $type->size - 1));
        $max = gmp_sub(gmp_pow(2, $type->size - 1), 1);
        if (gmp_cmp($n, $min) < 0 || gmp_cmp($n, $max) > 0) {
            throw new AbiException("int{$type->size} 数值越界：{$value}");
        }

        // 负数取 256 位二补码（sign-extend 到整个字）
        return self::wordOf(gmp_mod($n, gmp_pow(2, 256)));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value) && in_array($value, ['0', '1', 'true', 'false'], true)) {
            return $value === '1' || $value === 'true';
        }

        throw new AbiException('bool 参数只接受 true/false、0/1、\'1\'/\'0\'，收到：' . print_r($value, true));
    }

    private static function addressHex(mixed $value): string
    {
        if (!is_string($value) || !Address::isValid($value)) {
            throw new AbiException('address 参数必须是 0x + 40 位 hex 字符串，收到：' . print_r($value, true));
        }

        return str_pad(strtolower(Hex::stripPrefix($value)), 40, '0', STR_PAD_LEFT);
    }

    private static function wordFixedBytes(int $size, mixed $value): string
    {
        $hex = self::bytesHex($value);
        if (strlen($hex) / 2 > $size) {
            throw new AbiException("bytes{$size} 参数超出 {$size} 字节");
        }

        return Hex::padLeft32($hex);
    }

    /**
     * bytes 值统一为无 0x、偶数长度 hex（同时接受 0x 可选前缀）。
     */
    private static function bytesHex(mixed $value): string
    {
        if (!is_string($value)) {
            throw new AbiException('bytes 参数必须是 hex 字符串，收到 ' . get_debug_type($value));
        }
        $hex = Hex::stripPrefix($value);
        if ($hex === '') {
            return '';
        }
        if (strlen($hex) % 2 === 1 || !preg_match('/^[0-9a-fA-F]+$/', $hex)) {
            throw new AbiException('bytes 参数必须是偶数长度的 hex 字符串（建议带 0x 前缀）：' . substr($value, 0, 64));
        }

        return $hex;
    }

    /**
     * @return array<int,mixed>
     */
    private static function tupleValues(ParamType $type, mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            throw new AbiException('tuple/struct 参数必须是数组（按字段名或位置），收到 ' . get_debug_type($value));
        }

        $count = count($type->components);
        $out = [];

        if (array_is_list($value)) {
            if (count($value) !== $count) {
                throw new AbiException("tuple 参数个数不匹配：期望 {$count} 个，实际 " . count($value) . ' 个');
            }

            return array_values($value);
        }

        foreach ($type->components as $i => $component) {
            $name = $component->name;
            if ($name !== null && $name !== '' && array_key_exists($name, $value)) {
                $out[] = $value[$name];
            } elseif (array_key_exists($i, $value)) {
                $out[] = $value[$i];
            } else {
                throw new AbiException("tuple 缺少字段 {$name}（位置 {$i}）");
            }
        }

        return $out;
    }

    /**
     * @return array<int,mixed>
     */
    private static function arrayItems(ParamType $type, mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new AbiException('数组参数必须是顺序数组（list），收到关联数组');
        }
        if ($type->size !== null && count($value) !== $type->size) {
            throw new AbiException("定长数组参数个数不匹配：期望 {$type->size} 个，实际 " . count($value) . ' 个');
        }

        return $value;
    }

    // —— 字（32 字节）工具 ——————————————————————————————————————————

    private static function wordOf(int|\GMP $n): string
    {
        return Hex::padLeft32(gmp_strval(is_int($n) ? gmp_init($n) : $n, 16));
    }
}
