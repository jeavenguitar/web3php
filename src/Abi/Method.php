<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Utils\Crypto;

/**
 * ABI 里的一个函数定义（输入/输出已解析为 ParamType，并算好选择子）。
 */
final class Method
{
    /**
     * @param ParamType[] $inputs
     * @param ParamType[] $outputs
     */
    public function __construct(
        public readonly string $name,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly string $signature,
        public readonly string $selector,
        public readonly string $stateMutability = 'nonpayable',
    ) {
    }

    /**
     * @param array<string,mixed> $item ABI JSON 里 type=function 的条目
     */
    public static function fromAbiItem(array $item): self
    {
        $inputs = [];
        foreach ((array) ($item['inputs'] ?? []) as $input) {
            $inputs[] = ParamType::fromAbiItem((array) $input);
        }
        $outputs = [];
        foreach ((array) ($item['outputs'] ?? []) as $output) {
            $outputs[] = ParamType::fromAbiItem((array) $output);
        }

        $signature = ($item['name'] ?? '') . '(' . implode(',', array_map(fn (ParamType $t) => $t->canonical(), $inputs)) . ')';

        return new self(
            (string) ($item['name'] ?? ''),
            $inputs,
            $outputs,
            $signature,
            Crypto::selector($signature),
            (string) ($item['stateMutability'] ?? 'nonpayable'),
        );
    }
}
