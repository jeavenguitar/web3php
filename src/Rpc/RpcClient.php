<?php

declare(strict_types=1);

namespace Web3php\Rpc;

use Web3php\Exception\RpcException;

/**
 * 稳健的 JSON-RPC 客户端。
 *
 * 为什么不用现成的包：常见 web3 PHP 库默认 1 秒超时、连接无复用、
 * 节点抖动直接把异常抛给业务。这里的实现来自生产环境长期打磨：
 *
 *  - keep-alive 连接复用：同一节点多次请求共享 curl 句柄，省掉
 *    DNS + TCP + TLS 握手（公网节点单次握手 1~3 秒）；
 *  - 双层超时：connect_timeout 管建连，timeout 管总耗时，节点挂起
 *    不至于拖死进程；
 *  - 陈旧长连接自愈：复用的连接可能刚被节点按空闲超时关闭（表现为
 *    reset / 空响应），换新句柄原样重试；
 *  - 失败重试 + 多节点故障转移：传输层错误在当前节点退避重试，
 *    耗尽后切换下一个节点；某节点持续失败会自动被"跳过"（粘住成功节点）；
 *  - 空响应防御：既无 result 也无 error 的报文、HTTP 非 200、非 JSON
 *    （如网关 502 的 HTML 页）一律按传输失败处理，可重试；
 *  - RPC 业务错误（revert、参数错误等）不盲目重试——重试也必然失败，
 *    直接抛 RpcException 并带上节点地址，方便定位；
 *  - 可配置的 failover_error_codes：个别节点对某些查询返回错误而
 *    另一个节点查得到（例如部分公共节点查久远回执返回 -32602），
 *    命中这些错误码时自动换节点再试。
 */
class RpcClient
{
    public const DEFAULT_OPTIONS = [
        // 单次请求总超时（秒）——防节点挂起无限阻塞
        'timeout' => 60,
        // 建立连接超时（秒）
        'connect_timeout' => 15,
        // 同一节点的最大尝试次数
        'retries' => 3,
        // 重试退避基数（毫秒），线性递增：第 n 次等待 retry_delay_ms * n
        'retry_delay_ms' => 200,
        // 多节点时失败是否自动切换下一个节点
        'failover' => true,
        // 命中这些 RPC error code 时切换节点重试（如 [-32602]）
        'failover_error_codes' => [],
        // 额外 HTTP 头（如鉴权）
        'headers' => [],
        // 是否校验 TLS 证书
        'verify_ssl' => true,
        'user_agent' => 'web3php/1.0',
    ];

    /** @var array<int,array{url:string,handle:\CurlHandle|null}> */
    private array $endpoints = [];

    private int $current = 0;

    private array $options;

    private int $nextId = 1;

    private ?string $lastError = null;

    private ?string $lastEndpoint = null;

    /**
     * @param string|array $endpoints 单个 RPC URL 或多个（推荐多个，故障自动转移）
     * @param array<string,mixed> $options 见 DEFAULT_OPTIONS
     */
    public function __construct(string|array $endpoints, array $options = [])
    {
        $endpoints = array_values(array_unique((array) $endpoints));
        if ($endpoints === []) {
            throw new RpcException('至少需要一个 RPC 端点');
        }
        foreach ($endpoints as $url) {
            if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
                throw new RpcException('RPC 端点必须是 http(s) URL：' . print_r($url, true));
            }
            $this->endpoints[] = ['url' => rtrim($url, '/'), 'handle' => null];
        }

        $this->options = array_merge(self::DEFAULT_OPTIONS, $options);
    }

    public function __destruct()
    {
        // PHP 8 起 curl 句柄随对象回收自动释放，curl_close() 已弃用，置空引用即可
        foreach ($this->endpoints as &$endpoint) {
            $endpoint['handle'] = null;
        }
    }

    /**
     * 单次请求；成功返回 result（可能为 null，如 pending 回执），
     * 最终失败抛 RpcException（message 汇总了每个节点的失败原因）。
     */
    public function request(string $method, array $params = []): mixed
    {
        $out = $this->execute([[
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => $method,
            'params' => $params,
        ]]);

        $result = $out[0];
        if ($result instanceof RpcException) {
            throw $result;
        }

        return $result;
    }

    /**
     * JSON-RPC 批量请求。返回与入参同序的结果数组；
     * 单条失败不拖垮整批——失败的那一项是 RpcException 实例，其余照常返回。
     *
     * @param array<int,array{method:string,params?:array}> $calls
     * @return array<int,mixed>
     */
    public function batch(array $calls): array
    {
        $payloads = [];
        foreach ($calls as $call) {
            $payloads[] = [
                'jsonrpc' => '2.0',
                'id' => $this->nextId++,
                'method' => (string) $call['method'],
                'params' => $call['params'] ?? [],
            ];
        }

        return $this->execute($payloads);
    }

    /**
     * 最近一次失败链的原因（成功后为 null），排障用。
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * 最近一次成功命中的节点 URL。
     */
    public function lastEndpoint(): ?string
    {
        return $this->lastEndpoint;
    }

    /**
     * @return array<int,string>
     */
    public function endpointUrls(): array
    {
        return array_map(fn (array $ep) => $ep['url'], $this->endpoints);
    }

    // —— 执行引擎 —————————————————————————————————————————————————

    /**
     * @param array<int,array<string,mixed>> $payloads
     * @return array<int,mixed> 与 $payloads 同序的结果（或 RpcException 实例）
     */
    private function execute(array $payloads): array
    {
        $this->lastError = null;

        $order = range(0, count($this->endpoints) - 1);
        if ($this->options['failover']) {
            // 从当前粘住的节点开始轮转
            $order = array_merge(
                array_slice($order, $this->current),
                array_slice($order, 0, $this->current),
            );
        } else {
            $order = [$this->current];
        }

        $attempts = max(1, (int) $this->options['retries']);

        foreach ($order as $index) {
            $url = $this->endpoints[$index]['url'];

            for ($try = 1; $try <= $attempts; $try++) {
                try {
                    $responses = $this->perform($index, $payloads);
                } catch (TransportFailure $failure) {
                    $this->lastError = sprintf('%s 第 %d/%d 次尝试失败：%s', $url, $try, $attempts, $failure->getMessage());
                    usleep((int) $this->options['retry_delay_ms'] * $try * 1000);
                    continue;
                }

                $parsed = $this->parseResponses($responses, $payloads);
                if ($parsed['ok'] === null) {
                    // 传输层成功但报文异常（空响应/响应缺 id/可转移的 error code）
                    if (!empty($parsed['switch'])) {
                        $this->lastError = sprintf('%s：%s（切换下一节点）', $url, $parsed['retry']);
                        continue 2;
                    }
                    $this->lastError = sprintf('%s 第 %d/%d 次尝试：%s', $url, $try, $attempts, $parsed['retry']);
                    usleep((int) $this->options['retry_delay_ms'] * $try * 1000);
                    continue;
                }

                // 成功：粘住该节点，后续请求继续复用其 keep-alive 连接
                $this->current = $index;
                $this->lastEndpoint = $url;

                $out = [];
                foreach ($payloads as $payload) {
                    $out[] = $parsed['ok'][$payload['id']];
                }

                return $out;
            }
        }

        throw new RpcException('所有 RPC 尝试均失败。' . ($this->lastError ?? ''));
    }

    /**
     * 在指定节点上发出 HTTP 请求。
     *
     * @param array<int,array<string,mixed>> $payloads
     * @return array<int,\stdClass>
     */
    private function perform(int $index, array $payloads): array
    {
        $body = json_encode($payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new TransportFailure('请求体 JSON 编码失败：' . json_last_error_msg());
        }

        $handle = $this->handleFor($index);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);

        $raw = curl_exec($handle);

        if ($raw === false) {
            $error = sprintf('cURL %d：%s', curl_errno($handle), curl_error($handle));
            $this->dropHandle($index);
            throw new TransportFailure($error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $text = trim((string) $raw);

        if ($text === '') {
            // 空响应防御：连接建立但无报文（节点抖动 / 陈旧长连接被关闭的典型表现）
            $this->dropHandle($index);
            throw new TransportFailure("HTTP {$status} 响应体为空");
        }

        $decoded = json_decode($text);
        if (json_last_error() !== JSON_ERROR_NONE || (!is_array($decoded) && !$decoded instanceof \stdClass)) {
            // 非 JSON 防御：网关 502/429 常返回 HTML 页
            $this->dropHandle($index);
            throw new TransportFailure(sprintf(
                'HTTP %s 返回非 JSON（%s）：%s',
                $status,
                json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : '结构异常',
                mb_substr($text, 0, 200),
            ));
        }

        // 规范允许单条请求返回单对象（非数组）
        if ($decoded instanceof \stdClass) {
            $decoded = [$decoded];
        }

        return array_values(array_map(fn ($item) => (object) $item, $decoded));
    }

    /**
     * 解析报文：分类为 成功 / 可重试 / 直接失败。
     *
     * @param array<int,\stdClass> $responses
     * @param array<int,array<string,mixed>> $payloads
     * @return array{ok:array<int|string,mixed>|null,retry:string,switch:bool}
     */
    private function parseResponses(array $responses, array $payloads): array
    {
        /** @var array<int|string,mixed> $results */
        $results = [];
        $failoverCodes = array_map('intval', (array) $this->options['failover_error_codes']);

        foreach ($responses as $response) {
            $id = (int) ($response->id ?? -1);

            if (isset($response->error)) {
                $code = (int) ($response->error->code ?? 0);
                $message = (string) ($response->error->message ?? '');
                $data = $response->error->data ?? null;

                if (in_array($code, $failoverCodes, true)) {
                    return ['ok' => null, 'retry' => "节点返回错误 {$code}：{$message}", 'switch' => true];
                }

                // 业务错误（revert、参数错误…）重试无意义，直接构造异常；
                // 交给上层决定抛出（request）还是内嵌返回（batch）
                $results[$id] = new RpcException("RPC 错误 [{$code}] {$message}", $code, $data);
                continue;
            }

            if (!property_exists($response, 'result')) {
                return ['ok' => null, 'retry' => '响应既无 result 也无 error（空响应）', 'switch' => false];
            }

            $results[$id] = $response->result;
        }

        // 校验每个请求都有对应响应
        foreach ($payloads as $payload) {
            if (!array_key_exists($payload['id'], $results)) {
                return ['ok' => null, 'retry' => '响应缺少 id=' . $payload['id'] . ' 的条目', 'switch' => false];
            }
        }

        return ['ok' => $results, 'retry' => '', 'switch' => false];
    }

    // —— curl 句柄管理 ————————————————————————————————————————————

    /**
     * 取（或新建）节点专属的 keep-alive 句柄。
     */
    private function handleFor(int $index): \CurlHandle
    {
        $endpoint = &$this->endpoints[$index];

        if ($endpoint['handle'] === null) {
            $handle = curl_init($endpoint['url']);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => (int) $this->options['connect_timeout'],
                CURLOPT_TIMEOUT => (int) $this->options['timeout'],
                CURLOPT_SSL_VERIFYPEER => (bool) $this->options['verify_ssl'],
                CURLOPT_SSL_VERIFYHOST => $this->options['verify_ssl'] ? 2 : 0,
                CURLOPT_ENCODING => '',
            ]);
            $endpoint['handle'] = $handle;
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($this->options['user_agent'])) {
            $headers[] = 'User-Agent: ' . $this->options['user_agent'];
        }
        foreach ((array) $this->options['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        curl_setopt($endpoint['handle'], CURLOPT_HTTPHEADER, $headers);

        return $endpoint['handle'];
    }

    /**
     * 丢弃句柄（失败后强制下次重建连接，自愈被节点关闭的陈旧长连接）。
     * PHP 8 起 curl_close() 无效果，置空引用即可释放。
     */
    private function dropHandle(int $index): void
    {
        $this->endpoints[$index]['handle'] = null;
    }
}
