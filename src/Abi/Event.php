<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Utils\Crypto;

/**
 * ABI 里的一个事件定义（输入已解析为 ParamType，并记下哪些是 indexed，算好 topic0）。
 */
final class Event
{
    /**
     * @param ParamType[] $inputs  与 $indexed 一一对应
     * @param bool[] $indexed
     */
    public function __construct(
        public readonly string $name,
        public readonly array $inputs,
        public readonly array $indexed,
        public readonly string $signature,
        public readonly string $topic0,
        public readonly bool $anonymous = false,
    ) {
    }

    /**
     * @param array<string,mixed> $item ABI JSON 里 type=event 的条目
     */
    public static function fromAbiItem(array $item): self
    {
        $inputs = [];
        $indexed = [];
        foreach ((array) ($item['inputs'] ?? []) as $input) {
            $input = (array) $input;
            $inputs[] = ParamType::fromAbiItem($input);
            $indexed[] = (bool) ($input['indexed'] ?? false);
        }

        $signature = ($item['name'] ?? '') . '(' . implode(',', array_map(fn (ParamType $t) => $t->canonical(), $inputs)) . ')';

        return new self(
            (string) ($item['name'] ?? ''),
            $inputs,
            $indexed,
            $signature,
            Crypto::keccakBin($signature),
            (bool) ($item['anonymous'] ?? false),
        );
    }
}
