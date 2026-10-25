<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Exception\AbiException;
use Web3php\Utils\Hex;

/**
 * 无 ABI 时的尽力解析：
 *  - calldata：取 4 字节选择子，命中内置常见签名（ERC20 / ERC721 / WETH /
 *    Uniswap V2 Router 等）则按签名直接解码参数；未命中则按 32 字节字切分原始数据。
 *  - 事件日志：按 topic0 命中内置常见事件（Transfer / Approval / …）则解码，
 *    未命中返回原始 topics 与 data 字列表。
 *
 * 常量表之外的长尾签名需要外部签名库（4byte.directory、openchain）或拿到
 * 合约 ABI 后走 Contract::decodeInput() / decodeLogs() 精确解码。
 */
final class RawDecoder
{
    /** 选择子（8 hex，无 0x）→ [方法名, 参数类型列表] */
    public const METHODS = [
        'a9059cbb' => ['transfer', ['address', 'uint256']],
        '23b872dd' => ['transferFrom', ['address', 'address', 'uint256']],
        '095ea7b3' => ['approve', ['address', 'uint256']],
        'dd62ed3e' => ['allowance', ['address', 'address']],
        '70a08231' => ['balanceOf', ['address']],
        '18160ddd' => ['totalSupply', []],
        '40c10f19' => ['mint', ['address', 'uint256']],
        '9dc29fac' => ['burn', ['address', 'uint256']],
        'd0e30db0' => ['deposit', []],
        '2e1a7d4d' => ['withdraw', ['uint256']],
        '6352211e' => ['ownerOf', ['uint256']],
        '42842e0e' => ['safeTransferFrom', ['address', 'address', 'uint256']],
        'a22cb465' => ['setApprovalForAll', ['address', 'bool']],
        '38ed1739' => ['swapExactTokensForTokens', ['uint256', 'uint256', 'address[]', 'address', 'uint256']],
        '18cbafe5' => ['swapExactTokensForETH', ['uint256', 'uint256', 'address[]', 'address', 'uint256']],
        '7ff36ab5' => ['swapExactETHForTokens', ['uint256', 'address[]', 'address', 'uint256']],
        '8803dbee' => ['swapTokensForExactTokens', ['uint256', 'uint256', 'address[]', 'address', 'uint256']],
    ];

    /** topic0（64 hex，无 0x）→ [事件名, indexed 类型列表, data 类型列表] */
    public const EVENTS = [
        'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef' => ['Transfer', ['address', 'address'], ['uint256']],
        '8c5be1e5ebec7d5bd14f71427d1e84f3dd0314c0f7b2291e5b200ac8c7c3b925' => ['Approval', ['address', 'address'], ['uint256']],
        '17307eab39ab6107e8899845ad3d59bd9653f200f220920489ca2b5937696c31' => ['ApprovalForAll', ['address', 'address'], ['bool']],
        'c3d58168c5ae7397731d063d5bbf3d657854427343f4c083240f7aacaa2d0f62' => ['TransferSingle', ['address', 'address', 'address'], ['uint256', 'uint256']],
        'd78ad95fa46c994b6551d0da85fc275fe613ce37657fb8d5e3d130840159d822' => ['Swap', ['address'], ['uint256', 'uint256', 'uint256', 'uint256', 'address']],
        '1c411e9a96e071241c2f21f7726b17ae89e3cab4c78be50e062b03a9fffbbad1' => ['Sync', [], ['uint112', 'uint112']],
    ];

    /**
     * 解析 eth_getTransactionByHash 的结果：交易上下文 + calldata 尽力解码。
     *
     * @return array{from:?string, to:?string, value:string, selector:?string, method:?string, signature:?string, args:array<int,mixed>, words:list<string>}
     */
    public static function parseTransaction(object|array $tx): array
    {
        $tx = (object) $tx;

        $parsed = self::parseInput((string) ($tx->input ?? '0x'));
        $parsed['from'] = isset($tx->from) ? (string) $tx->from : null;
        $parsed['to'] = isset($tx->to) ? (string) $tx->to : null;
        $parsed['value'] = Hex::toDec((string) ($tx->value ?? '0x0'));

        return $parsed;
    }

    /**
     * 尽力解析 calldata：命中内置签名则解码参数（method / signature / args 有值）；
     * 未命中则 method=null，words 给出按 32 字节切分的原始字。
     * input 为 '0x'（纯主币转账）时 selector=null。
     *
     * @return array{selector:?string, method:?string, signature:?string, args:array<int,mixed>, words:list<string>}
     */
    public static function parseInput(string $input): array
    {
        $hex = Hex::stripPrefix($input);
        if ($hex === '') {
            return ['selector' => null, 'method' => null, 'signature' => null, 'args' => [], 'words' => []];
        }
        if (strlen($hex) < 8 || !ctype_xdigit($hex)) {
            throw new AbiException('非法的 calldata（hex，选择子 + 参数）：' . substr($input, 0, 74));
        }

        $selector = substr($hex, 0, 8);
        $body = '0x' . substr($hex, 8);

        $known = self::METHODS[strtolower($selector)] ?? null;
        if ($known === null) {
            return [
                'selector' => '0x' . strtolower($selector),
                'method' => null,
                'signature' => null,
                'args' => [],
                'words' => self::splitWords($body),
            ];
        }

        [$name, $types] = $known;

        return [
            'selector' => '0x' . strtolower($selector),
            'method' => $name,
            'signature' => $name . '(' . implode(',', $types) . ')',
            'args' => self::decodeParams($types, $body),
            'words' => [],
        ];
    }

    /**
     * 尽力解析一条日志：命中内置事件则解码（event / signature / args 有值），
     * 未命中则 event=null，topics / dataWords 保留原始数据。
     *
     * @param object|array{address?:string, topics?:array<string>, data?:string} $log
     * @return array{address:?string, topic0:?string, event:?string, signature:?string, args:array<int,mixed>, topics:list<string>, dataWords:list<string>}
     */
    public static function parseLog(object|array $log): array
    {
        $log = (object) $log;
        $topics = array_map(strval(...), (array) ($log->topics ?? []));
        $data = (string) ($log->data ?? '0x');

        $out = [
            'address' => isset($log->address) ? (string) $log->address : null,
            'topic0' => $topics[0] ?? null,
            'event' => null,
            'signature' => null,
            'args' => [],
            'topics' => $topics,
            'dataWords' => self::splitWords($data),
        ];

        if ($topics === []) {
            return $out;
        }

        $known = self::EVENTS[strtolower(Hex::stripPrefix($topics[0]))] ?? null;
        if ($known === null) {
            return $out;
        }

        [$name, $indexedTypes, $dataTypes] = $known;

        if (count($topics) - 1 !== count($indexedTypes)) {
            throw new AbiException(sprintf(
                '%s 事件的 topics 数量不匹配：期望 %d 个 indexed 参数 topic，实际 %d 个',
                $name,
                count($indexedTypes),
                max(0, count($topics) - 1),
            ));
        }

        $args = [];
        foreach ($indexedTypes as $i => $type) {
            $args[$i] = Decoder::decode([ParamType::parse($type)], $topics[$i + 1])[0];
        }
        foreach (self::decodeParams($dataTypes, $data) as $i => $value) {
            $args[count($indexedTypes) + $i] = $value;
        }

        $out['event'] = $name;
        $out['signature'] = $name . '(' . implode(',', [...$indexedTypes, ...$dataTypes]) . ')';
        $out['args'] = $args;

        return $out;
    }

    /**
     * 把 data 按 32 字节字切分（无 ABI 时人工 inspection 用）。
     *
     * @return list<string>
     */
    public static function splitWords(string $dataHex): array
    {
        $hex = Hex::stripPrefix($dataHex);
        if ($hex === '') {
            return [];
        }
        if (!ctype_xdigit($hex) || strlen($hex) % 64 !== 0) {
            throw new AbiException('数据长度不是 32 字节的整数倍，无法按字切分（长度 ' . strlen($hex) . ' hex 字符）');
        }

        return array_map(fn (string $word) => '0x' . $word, str_split($hex, 64));
    }

    /**
     * @param list<string> $types
     * @return array<int,mixed>
     */
    private static function decodeParams(array $types, string $dataHex): array
    {
        if ($types === []) {
            return [];
        }
        if (Hex::stripPrefix($dataHex) === '') {
            throw new AbiException('calldata/data 为空，但签名声明了参数');
        }

        $params = array_map(fn (string $type) => ParamType::parse($type), $types);

        return Decoder::decode($params, $dataHex);
    }
}
