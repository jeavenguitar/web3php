# web3php

PHP 版 web3.js —— 面向以太坊 / EVM 兼容链（BSC、Arbitrum、Polygon、Base 等）的开发工具包。

核心目标：**像 web3.js 一样好用，但把「RPC 节点抖动」当作一等公民来对待。**

```php
$web3 = new Web3php\Web3(['https://bsc-dataseed.bnbchain.org']);

$usdt = $web3->contract($abiJson)->at('0x55d398326f99059fF775485246999027B3197955');

$usdt->call('name');                        // "Tether USD"（ABI 解码，直接拿到标量）
$usdt->call('balanceOf', $userAddress);     // "1234500000000000000"（uint256 → 十进制字符串，不丢精度）

$txHash = $usdt->send('transfer', [$to, '1000000'], $wallet);  // 本地签名 + 自动补费 + 广播
```

## 为什么再造一个轮子

社区常用的 web3 PHP 包在生产上反复被这些问题坑：

| 痛点 | 本包的做法 |
| --- | --- |
| 默认 1 秒超时，节点稍慢就抛异常 | 连接超时 15s + 总超时 60s 双层可配，防挂起也防误杀 |
| 每次请求重做 DNS/TCP/TLS 握手 | 按节点复用 keep-alive curl 句柄（公网节点单次握手 1~3 秒） |
| 节点偶发断连直接炸业务 | 陈旧长连接自动换句柄重试；失败按线性退避重试 |
| 单节点，挂了就全挂 | 多端点列表 + 自动故障转移，成功后粘住命中节点复用连接 |
| 空响应 / 网关 502 HTML 页静默失败 | 既无 result 也无 error、HTTP 非 200、非 JSON 一律按传输失败重试 |
| `tuple[]` 解码崩溃、多值返回键名漂移 | 完整 ABI v2 编解码（嵌套 tuple / 定长与动态数组 / 任意组合），多值输出全命名时只返回字段名键，与链上结构一致 |
| 返回数据被截断时静默给出错值 | 解码前逐字节校验长度，截断即抛明确异常 |
| revert 只抛一个 "execution reverted" | 自动解出 `Error(string)` / `Panic(uint256)` 的人类可读原因 |
| 数值精度丢失（float/int 硬转） | 全链路 GMP：uint256 一律十进制字符串；编码侧拒绝浮点入参 |
| 交易签名依赖节点 `personal_unlockAccount` | 本地 secp256k1 签名（EIP-155 / EIP-1559），公网节点友好 |

## 安装

```bash
composer require jeavenguitar/web3php
```

要求 PHP >= 8.1，扩展：`curl`、`gmp`、`bcmath`、`json`。

## 快速上手

### 初始化（推荐多节点）

```php
use Web3php\Web3;

$web3 = new Web3([
    'https://bsc-dataseed.bnbchain.org',
    'https://bsc-dataseed1.defibit.io',
], [
    'timeout'          => 60,   // 单次请求总超时（秒）
    'connect_timeout'  => 15,   // 建连超时（秒）
    'retries'          => 3,    // 同节点最大尝试次数
    'retry_delay_ms'   => 200,  // 重试退避基数（第 n 次等 n × 200ms）
    'failover'         => true, // 多节点自动切换
    // 'failover_error_codes' => [-32602], // 个别节点查不到的数据换节点再查（如久远回执）
    // 'headers' => ['Authorization' => 'Bearer ...'], // 节点鉴权头
    // 'verify_ssl' => true,
]);
```

### 链数据

```php
$eth = $web3->eth();

$eth->blockNumber();                              // int
$eth->chainId();                                  // int（BSC=56）
$eth->gasPrice();                                 // "5000000000"（wei 十进制字符串）
$eth->getBalance($address);                       // 原生币余额（wei 字符串）
$eth->getTransactionCount($address);              // int nonce
$eth->getTransactionReceipt($hash);               // ?object（未上链返回 null，不是错误）
$eth->getBlockByNumber('latest', false);          // 区块
$eth->getLogs(['address' => $token, 'fromBlock' => $from]); // 事件日志

$receipt = $eth->waitForReceipt($hash, 60.0);     // 轮询等回执
Eth::isSuccessful($receipt);                      // status == 0x1
```

大数（余额、gas、totalSupply…）统一返回十进制字符串，配合 `bcmath` / `GMP` 使用：

```php
$decimals = $token->call('decimals');
$display  = bcdiv($token->call('balanceOf', $user), bcpow('10', $decimals), 6);
```

### 合约查询（eth_call + ABI 自动解码）

```php
$abi = file_get_contents('ERC20.json');           // remix/hardhat 导出的 ABI 直接用
$token = $web3->contract($abi)->at($contractAddress);

// 单输出 → 直接是标量
$token->call('name');                             // string
$token->call('balanceOf', $user);                 // uint256 → 十进制字符串
$token->call('decimals');                         // uint8 → "18"

// 多输出 → 全部有名字时只返回字段名键（与链上一致，没有 0/1/2 混在里面）
$r = $pair->call('getReserves');
$r['reserve0'];                                  // 直接按字段名取
// 有未命名输出时回退数字键，命名的照样给名字键

// struct / tuple 数组：每个元素是「字段名 => 值」数组，长度 = 元素个数
$infos = $nft->call('getGlobalNFTs', 0, 50);      // 50 个元素的数组，不会按 word 数误截

// 指定区块 / 模拟 from
$token->callAt('balanceOf', [$user], ['block' => 19000000, 'from' => $other]);

// 只编码不解码（自己组 eth_call / 监听 methodId）
$calldata = $token->encode('transfer', [$to, '1000000']);
$values   = $token->decode('getReserves', $hexFromRpc);
```

### 发送交易（本地签名 + 自动补费）

```php
$wallet = $web3->wallet($privateKey);             // 或 Web3php\Wallet::create() 新建

// 裸转账
$hash = $web3->sendTransaction($wallet, [
    'to'    => $payee,
    'value' => '1000000000000000000',             // 1 BNB（wei，字符串）
]);

// 合约方法调用：nonce / gas / gasPrice / chainId 全自动
$hash = $token->send('transfer', [$to, '1000000'], $wallet);

// 手动覆盖费用 / 切 EIP-1559
$hash = $token->send('transfer', [$to, '1000000'], $wallet, [
    'gas'              => '65000',
    'maxFeePerGas'     => '30000000000',          // 给了它就是 type-2 交易
    'maxPriorityFeePerGas' => '1000000000',
]);

$receipt = $web3->eth()->waitForReceipt($hash);
```

`sendTransaction` 缺省行为：nonce 取 `pending`、gasPrice 取 `eth_gasPrice`、gas 走 `eth_estimateGas`；
estimateGas 被 revert 时会抛出带人类可读原因的异常（`Error(string)` / `Panic(uint256)` 自动解码）。

### 预估手续费（eth_estimateGas）

```php
$eth = $web3->eth();

// 1) 主币转账预估（BNB / ETH 等；value 传 0x hex）
$gas = $eth->estimateGas([
    'from'  => $wallet->address(),               // 余额检查会用到 from
    'to'    => $payee,
    'value' => '0x2386f26fc10000',               // 0.01 BNB = Hex::decToHex('10000000000000000')
]);
// "21000" —— 普通主币转账固定 21000，不查也行

// 2) ERC20 合约转账预估（calldata 按 ABI 自动编码）
$gas = $token->estimateGas('transfer', [$to, '1000000'], $wallet->address());

// 3) 其他合约方法预估（approve / swap 等，参数照 send() 传）
$gas = $token->estimateGas('approve', [$spender, bcpow('10', '18')], $wallet->address());

// 4) 自己组 calldata 时用底层接口
$gas = $eth->estimateGas([
    'from' => $wallet->address(),
    'to'   => $token->address(),
    'data' => $token->encode('transfer', [$to, '1000000']),
]);
```

模拟执行被 revert 时**不抛异常，直接返回链上报错文本**（`Error(string)` / `Panic(uint256)` 自动解码），用 `ctype_digit()` 区分估值与报错：

```php
$gas = $token->estimateGas('transfer', [$to, '1000000'], $from);
// from 余额不足时 $gas = "Error(string): ERC20: transfer amount exceeds balance"

if (!ctype_digit($gas)) {
    exit("预估失败，链上报错：{$gas}\n");   // 原样展示给用户即可，不用 try/catch
}
```

正常时返回值是 gas 数量（十进制字符串），乘上 gas 单价才是手续费：

```php
$fees   = $eth->suggestFees();                        // ['legacy' => …, 'maxFeePerGas' => …, …]
$feeWei = bcmul($gas, $fees['legacy']);               // Legacy：gas × gasPrice
$maxWei = bcmul($gas, $fees['maxFeePerGas']);         // EIP-1559 上限，实际通常更低
printf("本笔手续费约 %s BNB\n", bcdiv($feeWei, bcpow('10', '18'), 6));
```

注意：

- **from 一定要传**：节点按 from 的当前状态模拟执行——主币余额不够、代币没授权，预估都会 revert（此时返回报错文本）。
- **只有节点完全不可达才抛 `RpcException`**（重试与故障转移已耗尽）；链上业务报错一律走返回值，不抛异常。
- gas 是基于当前状态的估值，内存池拥堵、合约状态变化后实际用量会浮动，展示给用户时建议加 10%~20% 余量。

### 批量查询

```php
$results = $web3->rpc()->batch([
    ['method' => 'eth_getBalance', 'params' => [$a, 'latest']],
    ['method' => 'eth_getBalance', 'params' => [$b, 'latest']],
]);
// 结果与入参同序；单条失败不拖垮整批，对应项是 RpcException 实例
```

## 错误语义

- 传输层问题（连接失败 / 超时 / HTTP 非 200 / 非 JSON / 空响应）**不直接抛**：先在当前节点退避重试，耗尽后切换下一个节点，全部失败才抛 `RpcException`（message 汇总每个节点的失败原因）。
- RPC 业务错误（`execution reverted`、参数错误等）**不盲目重试**（重试也必然失败），立即抛 `RpcException`，带 `rpcCode` / `rpcData`。
- `estimateGas` 是特例：模拟执行被 revert 属于「业务答案」而非故障——不抛异常，直接返回已解码的链上报错文本（`ctype_digit()` 区分估值与报错），只有节点不可达才抛 `RpcException`。
- `eth_getTransactionReceipt` 未上链返回 `null` —— pending 不是错误。
- `failover_error_codes`：个别公共节点对特定查询返回错误而别的节点查得到（例如部分节点查久远回执返回 `-32602`），把这类错误码配进来会自动换节点再查。

## 测试与验证

- PHPUnit 全离线：EIP-155 官方 worked example 逐字节复现、EIP-55 官方向量、RLP 官方向量、ERC20 选择子、ABI 全类型编解码往返、struct 数组长度语义、截断检测、EIP-1559 签名恢复闭环。
- 已对 BSC 主网实测：多节点故障转移、USDT 合约查询解码、批量查询、legacy 与 EIP-1559 交易签名（节点验签通过）。

```bash
composer install
vendor/bin/phpunit
```

## 目录结构

```
src/
├── Web3.php                门面入口
├── Eth.php                 eth_* / net_* API（值已归一化）
├── Wallet.php              私钥 → EIP-55 地址、签名
├── Contract/Contract.php   合约调用（call/encode/decode/estimateGas/send）
├── Rpc/RpcClient.php       稳健 JSON-RPC（重试/故障转移/keep-alive/批处理）
├── Abi/                    ABI 解析与编解码（ParamType/Abi/Encoder/Decoder）
├── Tx/                     RLP / 签名器（EIP-155、EIP-1559）/ 交易构建
├── Utils/                  Hex / Address(EIP-55) / Crypto(Keccak)
└── Exception/              异常体系
```

## 已知边界

- `fixed` / `ufixed` 类型未实现（极少有合约使用，遇到会抛出明确异常）。
- 事件 ABI（topic 解码）尚未内置，`getLogs` 返回原始日志对象，可配合 `Abi` 手工解码。
- 只支持 HTTP(S) RPC 端点（WebSocket 不支持）。

## License

MIT
