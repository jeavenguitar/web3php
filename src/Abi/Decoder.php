<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Exception\AbiException;
use Web3php\Utils\Address;
use Web3php\Utils\Hex;

/**
 * Solidity ABI 解码器：把 eth_call 返回的 hex 解析回 PHP 值。
 *
 * 值映射约定（与 web3.js 对齐，大数一律字符串防丢精度）：
 *   uintN          → 十进制字符串
 *   intN           → 带符号十进制字符串
 *   bool           → bool
 *   address        → EIP-55 校验和地址
 *   bytesN / bytes → 0x hex 字符串
 *   string         → UTF-8 字符串
 *   数组            → PHP 顺序数组
 *   tuple/struct   → 数组：数字键 + 字段名键（有名字时），多值输出同理
 */
final class Decoder
{
    /**
     * 解码参数列表。
     *
     * @param ParamType[] $types
     * @return array<int|string,mixed>
     */
    public static function decode(array $types, string $dataHex): array
    {
        $bin = Hex::toBin($dataHex);

        if ($bin === '') {
            if ($types === []) {
                return [];
            }
            // 有些节点对无返回值函数返回 '0x'，但函数声明了输出就是异常——显式报错，绝不静默
            throw new AbiException('合约返回了空数据，但函数声明了 ' . count($types) . ' 个输出');
        }

        $pos = 0;
        $out = [];
        foreach ($types as $i => $type) {
            $value = self::readValue($type, $bin, $pos, 0);
            $out[$i] = $value;
            if ($type->name !== null && $type->name !== '') {
                $out[$type->name] = $value;
            }
        }

        return self::preferNamedKeys($out, $types);
    }

    /**
     * 读取一个值；$pos 按头部尺寸前进，$blockStart 为本块 offset 基准。
     */
    private static function readValue(ParamType $type, string $bin, int &$pos, int $blockStart): mixed
    {
        switch ($type->base) {
            case 'uint':
                $word = self::need($bin, $pos, 32, $type);
                $pos += 32;

                return gmp_strval(gmp_init(bin2hex($word), 16), 10);

            case 'int':
                $word = self::need($bin, $pos, 32, $type);
                $pos += 32;
                $hex = bin2hex($word);
                $n = gmp_init($hex, 16);
                // 最高位为 1 → 负数（256 位二补码）
                if ($hex[0] >= '8') {
                    $n = gmp_sub($n, gmp_pow(2, 256));
                }

                return gmp_strval($n, 10);

            case 'bool':
                $word = self::need($bin, $pos, 32, $type);
                $pos += 32;

                return trim($word, "\x00") !== '';

            case 'address':
                $word = self::need($bin, $pos, 32, $type);
                $pos += 32;

                return Address::toChecksum('0x' . substr(bin2hex($word), 24));

            case 'bytes':
                if ($type->size !== null) {
                    $word = self::need($bin, $pos, 32, $type);
                    $pos += 32;

                    // bytesN 在字内右对齐（左补零），取低位 N 字节
                    return '0x' . substr(bin2hex($word), 64 - $type->size * 2);
                }

                [$length, $dataStart] = self::readLengthPrefixed($bin, $pos, $blockStart, $type);
                $data = self::need($bin, $dataStart, $length, $type);

                return '0x' . bin2hex($data);

            case 'string':
                [$length, $dataStart] = self::readLengthPrefixed($bin, $pos, $blockStart, $type);

                return self::need($bin, $dataStart, $length, $type);

            case 'array':
                if ($type->isDynamic()) {
                    $base = self::readOffset($bin, $pos, $blockStart, $type);
                    if ($type->size === null) {
                        // 长度字是动态数组自己数据区的一部分；元素 offset 以长度字之后为基准
                        $lengthHex = bin2hex(self::need($bin, $base, 32, $type));
                        $length = (int) gmp_strval(gmp_init($lengthHex, 16), 10);
                        $base += 32;
                    } else {
                        $length = $type->size;
                    }
                } else {
                    $base = $pos;
                    $length = $type->size;
                }

                $items = [];
                $p = $base;
                for ($i = 0; $i < $length; $i++) {
                    $items[] = self::readValue($type->element, $bin, $p, $base);
                }
                if (!$type->isDynamic()) {
                    $pos = $p;
                }

                return $items;

            case 'tuple':
                if ($type->isDynamic()) {
                    $base = self::readOffset($bin, $pos, $blockStart, $type);
                    $p = $base;
                } else {
                    $base = $pos;
                    $p = $pos;
                }

                $out = [];
                foreach ($type->components as $i => $component) {
                    $value = self::readValue($component, $bin, $p, $base);
                    $out[$i] = $value;
                    if ($component->name !== null && $component->name !== '') {
                        $out[$component->name] = $value;
                    }
                }
                if (!$type->isDynamic()) {
                    $pos = $p;
                }

                return self::preferNamedKeys($out, $type->components);
        }

        throw new AbiException('无法解码的类型基类：' . $type->base);
    }

    /**
     * @return array{0:int,1:int} [长度, 数据起始绝对偏移]
     */
    private static function readLengthPrefixed(string $bin, int &$pos, int $blockStart, ParamType $type): array
    {
        $dataStart = self::readOffset($bin, $pos, $blockStart, $type);
        $lengthHex = bin2hex(self::need($bin, $dataStart, 32, $type));

        return [(int) gmp_strval(gmp_init($lengthHex, 16), 10), $dataStart + 32];
    }

    private static function readOffset(string $bin, int &$pos, int $blockStart, ParamType $type): int
    {
        $word = self::need($bin, $pos, 32, $type);
        $pos += 32;
        $offset = (int) gmp_strval(gmp_init(bin2hex($word), 16), 10);
        $absolute = $blockStart + $offset;
        if ($offset < 0 || $absolute + 32 > strlen($bin)) {
            throw new AbiException('返回数据损坏：offset 越界（offset=' . $offset . '，数据长度 ' . strlen($bin) . ' 字节）');
        }

        return $absolute;
    }

    /**
     * 输出（或 tuple 成员）全都有唯一名字时去掉数字键，返回结构与链上/文档一致：
     * ['invitors' => …, 'teamAmounts' => …]；有未命名、重名或纯数字名时保留数字键兜底。
     *
     * @param array<int|string,mixed> $out
     * @param ParamType[] $types
     * @return array<int|string,mixed>
     */
    private static function preferNamedKeys(array $out, array $types): array
    {
        $names = [];
        foreach ($types as $type) {
            $name = $type->name;
            if ($name === null || $name === '' || ctype_digit($name) || isset($names[$name])) {
                return $out;
            }
            $names[$name] = true;
        }

        foreach (array_keys($out) as $key) {
            if (is_int($key)) {
                unset($out[$key]);
            }
        }

        return $out;
    }

    /**
     * 解码事件日志：indexed 参数依次取自 topics[1..]（anonymous 事件从 topics[0] 起），
     * 非 indexed 参数整体 ABI 编码在 data 里。返回值命名规则与 decode() 一致。
     *
     * 动态类型（string / bytes / 数组 / 含动态成员的 tuple）做 indexed 时，
     * 链上只存内容的 keccak-256 哈希——此时解出的就是该哈希，无法还原原文。
     *
     * @param string[] $topics
     * @return array<int|string,mixed>
     */
    public static function decodeEvent(Event $event, array $topics, string $dataHex): array
    {
        $firstParam = $event->anonymous ? 0 : 1;

        if (!$event->anonymous) {
            $signature = Hex::ensurePrefix((string) ($topics[0] ?? ''));
            if (Hex::stripPrefix($signature) !== Hex::stripPrefix($event->topic0)) {
                throw new AbiException('topic0 与事件签名不匹配：期望 ' . $event->topic0 . '，实际 ' . ($signature ?: '(空)'));
            }
        }

        $indexedTypes = array_values(array_filter(
            $event->inputs,
            fn (int $i) => $event->indexed[$i],
            ARRAY_FILTER_USE_KEY,
        ));
        $dataTypes = array_values(array_filter(
            $event->inputs,
            fn (int $i) => !$event->indexed[$i],
            ARRAY_FILTER_USE_KEY,
        ));

        if (count($topics) - $firstParam !== count($indexedTypes)) {
            throw new AbiException(sprintf(
                '事件 %s 的 topics 数量不匹配：期望 %d 个 indexed 参数 topic，实际 %d 个',
                $event->name,
                count($indexedTypes),
                max(0, count($topics) - $firstParam),
            ));
        }
        if ($dataTypes !== [] && Hex::stripPrefix($dataHex) === '') {
            throw new AbiException("事件 {$event->name} 的 data 为空，但 ABI 声明了非 indexed 参数");
        }

        $out = [];
        $topicIndex = $firstParam;
        foreach ($event->inputs as $i => $type) {
            if ($event->indexed[$i]) {
                $out[$i] = $type->isDynamic()
                    ? Hex::ensurePrefix((string) $topics[$topicIndex])
                    : self::decode([self::unnamed($type)], Hex::ensurePrefix((string) $topics[$topicIndex]))[0];
                $topicIndex++;
            }
        }

        // 非 indexed 参数整体编码在 data 里；按位置回填，命名键最后统一补
        $dataValues = self::decode(array_map(self::unnamed(...), $dataTypes), $dataHex);
        $dataIndex = 0;
        foreach ($event->inputs as $i => $type) {
            if (!$event->indexed[$i]) {
                $out[$i] = $dataValues[$dataIndex++];
            }
            if ($type->name !== null && $type->name !== '') {
                $out[$type->name] = $out[$i];
            }
        }

        return self::preferNamedKeys($out, $event->inputs);
    }

    /**
     * 去掉参数名的副本：decode() 对全命名类型会剥掉数字键，
     * 事件按位置取值时必须保证数字键存在。
     */
    private static function unnamed(ParamType $type): ParamType
    {
        $copy = clone $type;
        $copy->name = null;

        return $copy;
    }

    private static function need(string $bin, int $pos, int $length, ParamType $type): string
    {
        if ($pos < 0 || $length < 0 || $pos + $length > strlen($bin)) {
            throw new AbiException(sprintf(
                '返回数据被截断：解码 %s 至偏移 %d 需要 %d 字节，实际只有 %d 字节',
                $type->canonical(),
                $pos,
                $length,
                strlen($bin),
            ));
        }

        return substr($bin, $pos, $length);
    }

    // —— revert 原因解析 ——————————————————————————————————————————

    private const PANIC_REASONS = [
        '1' => 'assert 失败',
        '17' => '算术溢出/下溢',
        '18' => '除以零',
        '19' => '枚举越界',
        '33' => '非法的存储数组',
        '49' => 'pop 空数组',
        '50' => '数组索引越界',
        '65' => '内存越界',
        '81' => '未初始化的函数指针',
    ];

    /**
     * 尝试从 revert 数据解出人类可读原因（Error(string) / Panic(uint256)）。
     * $data 兼容 JSON-RPC error.data 的常见形态：hex 字符串 / {data: hex} / [hex]。
     */
    public static function decodeKnownError(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            $found = null;
            foreach ($data as $value) {
                if (is_string($value)) {
                    $found = $value;
                    break;
                }
            }
            $data = $found;
        }
        if (!is_string($data)) {
            return null;
        }

        $hex = Hex::stripPrefix($data);
        if (strlen($hex) < 8) {
            return null;
        }
        $selector = substr($hex, 0, 8);

        if ($selector === '08c379a0') {
            try {
                $args = self::decode([ParamType::parse('string')], '0x' . substr($hex, 8));

                return 'Error(string): ' . $args[0];
            } catch (AbiException) {
                return null;
            }
        }

        if ($selector === '4e487b71') {
            try {
                $args = self::decode([ParamType::parse('uint256')], '0x' . substr($hex, 8));
                $code = $args[0];

                return 'Panic(0x' . dechex((int) $code) . '): ' . (self::PANIC_REASONS[$code] ?? '未知原因');
            } catch (AbiException) {
                return null;
            }
        }

        return null;
    }
}
