<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Exception\AbiException;

/**
 * 解析后的 Solidity 参数类型（支持完整 ABI v2：tuple、定长/动态数组、任意嵌套）。
 *
 * $base 取值：
 *   uint / int        —— $size 为位宽（8~256，8 的倍数）
 *   bytes             —— $size 为字节数（1~32）；$size === null 表示动态 bytes
 *   bool / address / string
 *   tuple             —— $components 为成员 ParamType 列表
 *   array             —— $element 为元素类型；$size === null 表示动态数组，否则为定长
 */
class ParamType
{
    public ?string $name = null;

    /**
     * @param ParamType[] $components
     */
    public function __construct(
        public readonly string $base,
        public readonly ?int $size = null,
        public readonly ?ParamType $element = null,
        public readonly array $components = [],
    ) {
    }

    /**
     * @param array{name?:string,type:string,components?:array|null} $item ABI JSON 里的输入/输出条目
     */
    public static function fromAbiItem(array $item): self
    {
        $type = self::parse($item['type'] ?? '', $item['components'] ?? []);
        $type->name = $item['name'] ?? null;

        return $type;
    }

    /**
     * 解析类型字符串，如 "uint256"、"bytes32"、"tuple[2][]"。
     *
     * @param array<int,array<string,mixed>> $components tuple 的成员描述
     */
    public static function parse(string $type, array $components = []): self
    {
        $type = trim($type);

        // 数组后缀从最外层剥：uint256[2][] → (uint256[2]) 的动态数组
        if (preg_match('/^(.*)\[(\d*)\]$/', $type, $m) && $m[1] !== '') {
            $element = self::parse($m[1], $components);

            return new self('array', $m[2] === '' ? null : (int) $m[2], $element, []);
        }

        if ($type === 'tuple') {
            $comps = [];
            foreach ($components as $component) {
                $comps[] = self::fromAbiItem((array) $component);
            }

            return new self('tuple', null, null, $comps);
        }

        // uint / int 是 uint256 / int256 的别名（规范化，保证规范签名一致）
        if ($type === 'uint' || $type === 'int') {
            $type .= '256';
        }

        if (preg_match('/^(u?int)(\d+)$/', $type, $m) === 1) {
            $bits = (int) $m[2];
            if ($bits < 8 || $bits > 256 || $bits % 8 !== 0) {
                throw new AbiException("非法整型位宽：{$type}");
            }

            return new self($m[1] === 'uint' ? 'uint' : 'int', $bits);
        }

        if ($type === 'bytes') {
            return new self('bytes');
        }

        if (preg_match('/^bytes(\d+)$/', $type, $m) === 1) {
            $n = (int) $m[1];
            if ($n < 1 || $n > 32) {
                throw new AbiException("非法定长字节数：{$type}（1~32）");
            }

            return new self('bytes', $n);
        }

        if ($type === 'bool' || $type === 'address' || $type === 'string') {
            return new self($type);
        }

        throw new AbiException("暂不支持的类型：{$type}（fixed/ufixed 未实现）");
    }

    /**
     * 该类型（按值编码时）是否为动态类型，决定走 offset 定位还是内联。
     */
    public function isDynamic(): bool
    {
        return match ($this->base) {
            'string' => true,
            'bytes' => $this->size === null,
            'array' => $this->size === null || $this->element->isDynamic(),
            'tuple' => (bool) array_filter($this->components, fn (self $c) => $c->isDynamic()),
            default => false,
        };
    }

    /**
     * 该类型在"头部"占据的字节数：静态标量 32 字节；静态 tuple / 定长数组
     * 为成员之和并整体内联；动态类型只占一个 offset 字（32 字节）。
     */
    public function headSize(): int
    {
        if ($this->isDynamic()) {
            return 32;
        }

        return match ($this->base) {
            'array' => $this->size * $this->element->headSize(),
            'tuple' => array_sum(array_map(fn (self $c) => $c->headSize(), $this->components)),
            default => 32,
        };
    }

    /**
     * 规范类型名（用于函数签名与选择子计算），tuple 展开为 "(a,b)"。
     */
    public function canonical(): string
    {
        return match ($this->base) {
            'array' => $this->element->canonical() . '[' . ($this->size ?? '') . ']',
            'tuple' => '(' . implode(',', array_map(fn (self $c) => $c->canonical(), $this->components)) . ')',
            'uint', 'int' => $this->base . $this->size,
            'bytes' => $this->size === null ? 'bytes' : 'bytes' . $this->size,
            default => $this->base,
        };
    }
}
