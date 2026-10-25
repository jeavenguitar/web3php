<?php

declare(strict_types=1);

namespace Web3php\Abi;

use Web3php\Exception\AbiException;
use Web3php\Utils\Hex;

/**
 * ABI 容器：加载合约 ABI JSON，按方法名/参数个数解析重载，按选择子反查。
 */
final class Abi
{
    /** @var array<string, array<int, Method>> */
    private array $byName = [];

    /** @var array<string, Method> 选择子（8 hex，无 0x）→ Method */
    private array $bySelector = [];

    /** @var array<string, Event> topic0（64 hex，无 0x）→ Event */
    private array $eventsByTopic0 = [];

    /** @var list<Event> */
    private array $eventList = [];

    /** @var array<int, array<string,mixed>> 原始 ABI 条目（含 event 等，供扩展用） */
    private array $items = [];

    /**
     * @param string|array $abi JSON 字符串或已解码的数组
     */
    public function __construct(string|array $abi)
    {
        if (is_string($abi)) {
            $decoded = json_decode($abi);
            if (!is_array($decoded)) {
                throw new AbiException('ABI JSON 解析失败：' . json_last_error_msg());
            }
            $abi = $decoded;
        }

        foreach ($abi as $item) {
            $item = (array) $item;
            $this->items[] = $item;
            $type = $item['type'] ?? 'function';

            // 老版本 solc 导出的 ABI 可能没有 type 字段，缺省视为 function
            if ($type === 'function' && ($item['name'] ?? '') !== '') {
                $method = Method::fromAbiItem($item);
                $this->byName[$method->name][] = $method;
                $this->bySelector[Hex::stripPrefix($method->selector)] = $method;
            } elseif ($type === 'event' && ($item['name'] ?? '') !== '') {
                $event = Event::fromAbiItem($item);
                $this->eventList[] = $event;
                $this->eventsByTopic0[Hex::stripPrefix($event->topic0)] = $event;
            }
        }
    }

    /**
     * 解析方法；同名重载按参数个数匹配。
     *
     * @param int|null $argCount 传 null 表示未指定（仅当无重载时可用）
     */
    public function method(string $name, ?int $argCount = null): Method
    {
        if (!isset($this->byName[$name])) {
            throw new AbiException('ABI 中不存在方法 ' . $name . '（可用方法：' . implode(', ', array_keys($this->byName)) . '）');
        }

        $defs = $this->byName[$name];
        $arityList = implode('/', array_map(fn (Method $m) => (string) count($m->inputs), $defs));

        if ($argCount === null) {
            if (count($defs) > 1) {
                throw new AbiException("方法 {$name} 存在重载（参数个数 {$arityList}），无法仅凭名字确定，请直接传参以自动匹配");
            }

            return $defs[0];
        }

        foreach ($defs as $def) {
            if (count($def->inputs) === $argCount) {
                return $def;
            }
        }

        throw new AbiException("方法 {$name} 没有参数个数为 {$argCount} 的重载（现有参数个数：{$arityList}）");
    }

    public function bySelector(string $selector): ?Method
    {
        return $this->bySelector[Hex::stripPrefix($selector)] ?? null;
    }

    /**
     * 按 topic0（事件签名哈希）反查事件定义。
     */
    public function byTopic0(string $topic0): ?Event
    {
        return $this->eventsByTopic0[Hex::stripPrefix($topic0)] ?? null;
    }

    /**
     * @return list<Event>
     */
    public function events(): array
    {
        return $this->eventList;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * @return Method[]
     */
    public function methods(): array
    {
        $all = [];
        foreach ($this->byName as $defs) {
            foreach ($defs as $def) {
                $all[] = $def;
            }
        }

        return $all;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }
}
