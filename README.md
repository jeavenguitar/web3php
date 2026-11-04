# web3php

PHP 版 web3.js —— 面向以太坊 / EVM 兼容链（BSC、Arbitrum、Polygon、Base 等）的开发工具包。

核心目标：**像 web3.js 一样好用，但把「RPC 节点抖动」当作一等公民来对待。**

```php
$web3 = new Web3php\Web3(['https://bsc-dataseed.bnbchain.org']);

$usdt = $web3->contract($abiJson)->at('0x55d398326f99059fF775485246999027B3197955');

$usdt->call('name');                        // "Tether USD"（ABI 解码，直接拿到标量）
$usdt->call('balanceOf', $userAddress);     // "1234500000000000000"（uint256 → 十进制字符串，不丢精度）

$txResult = $usdt->send('transfer', [$to, '1000000'], $wallet); // 本地签名 + 自动补费 + 广播
$txResult['ok'];     // true：['hash' => '0x…', 'error' => null]
$txResult['error'];  // false 时给链上报错文本（不抛异常）
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

### 发送交易（本地签名 + 自动补费，失败不抛异常）

`sendTransaction` / 合约 `send()` 统一返回 `['ok' / 'hash' / 'error']`：

```php
$wallet = $web3->wallet($privateKey);             // 或 Web3php\Wallet::create() 新建

// 裸转账
$result = $web3->sendTransaction($wallet, [
    'to'    => $payee,
    'value' => '1000000000000000000',             // 1 BNB（wei，字符串）
]);
if (!$result['ok']) {
    exit('发送失败：' . $result['error'] . "\n");  // 如 "RPC 错误 [-32000] nonce too low"
}

// 合约方法调用：nonce / gas / gasPrice / chainId 全自动
$result = $token->send('transfer', [$to, '1000000'], $wallet);

// 手动覆盖费用 / 切 EIP-1559
$result = $token->send('transfer', [$to, '1000000'], $wallet, [
    'gas'              => '65000',
    'maxFeePerGas'     => '30000000000',          // 给了它就是 type-2 交易
    'maxPriorityFeePerGas' => '1000000000',
]);

$hash = $result['hash'];                          // 发送成功后，交易哈希在结果里

$receipt = $web3->eth()->waitForReceipt($hash);
```

`sendTransaction` 缺省行为：nonce 取 `pending`、gasPrice 取 `eth_gasPrice`、gas 走 `eth_estimateGas`。
链上拒绝（`nonce too low`、余额不足、预估 revert、广播被拒等）**不抛异常**，统一返回
`['ok' => false, 'hash' => null, 'error' => 报错文本]`（`Error(string)` / `Panic(uint256)` revert
原因自动解码）；只有节点完全不可达才抛 `RpcException`，本地参数校验失败抛 `TxException`
（那是调用代码的 bug，不是链上报错）。

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

### 解析交易 input data / 事件日志

**有 ABI（精确解码）**——拿到交易哈希后，`input` 按 4 字节选择子反查 ABI 方法，日志按 topic0 反查事件：

```php
$tx = $web3->eth()->getTransactionByHash($hash);

// input data 解析
$parsed = $token->decodeInput($tx->input);
// ['method' => 'transfer', 'signature' => 'transfer(address,uint256)',
//  'selector' => '0xa9059cbb', 'args' => ['to' => '0x…', 'amount' => '1000000']]
// 普通主币转账（input = 0x）时 method 为 null，不报错

// 事件日志解析（回执或 getLogs 的结果都能喂）
$receipt = $web3->eth()->getTransactionReceipt($hash);
foreach ($token->decodeLogs($receipt->logs) as $log) {
    $log['event'];   // "Transfer"；不在 ABI 中时为 null（原始日志在 $log['log']）
    $log['args'];    // ['from' => …, 'to' => …, 'value' => '1000000']
}
```

indexed 参数从 topics 解出；动态类型（string / bytes / 数组）做 indexed 时链上只存 keccak 哈希，解出的就是哈希原文。

**无 ABI（尽力识别）**——内置 ERC20 / ERC721 / WETH / Uniswap V2 等常见签名，命中即解码，未命中退化为原始 32 字节字列表：

```php
use Web3php\Abi\RawDecoder;

$parsed = RawDecoder::parseTransaction($tx);
// ['from' => …, 'to' => …, 'value' => '1000000000000000',
//  'selector' => '0xa9059cbb', 'method' => 'transfer',
//  'signature' => 'transfer(address,uint256)', 'args' => [0 => '0x…', 1 => '1000000'], 'words' => []]
// 不认识的方法：method = null，words 是 32 字节原始字列表（selector 仍会给出）

$log = RawDecoder::parseLog($receipt->logs[0]);
// ['address' => 合约地址, 'topic0' => …, 'event' => 'Transfer', 'args' => […],
//  'topics' => […], 'dataWords' => […]]；不认识的事件 event = null
```

常量表之外的长尾签名（代理合约、冷门协议）需要外部签名库（4byte.directory、openchain）或拿到 ABI 后走上面的精确路径。

## 单位与精度转换（Units）

`Web3\Utils\Units` 是 web3.js `web3.utils.fromWei` / `toWei` 的 PHP 实现，全部基于 GMP 大整数运算，**uint256 全程无精度丢失**。链上查询返回的 wei（或任意 ERC-20 最小单位）都能转换成任意精度的十进制，反之亦然。

### wei → 十进制（fromWei）

```php
use Web3php\Utils\Units;

Units::fromWei('1000000000000000000', 'ether');   // '1'
Units::fromWei('1234567890123456789', 'ether');   // '1.234567890123456789'
Units::fromWei('1500000000000000000', 'ether');   // '1.5'（尾部 0 自动去除）
Units::fromWei('20500000000', 'gwei');            // '20.5'
Units::fromWei('0xde0b6b3a7640000', 'ether');     // '1'（RPC 返回的 hex quantity 直接可用）
Units::fromWei('115792089237316195423570985008687907853269984665640564039457584007913129639935', 'ether');
// '115792089237316195423570985008687907853269984665640564039457.584007913129639935'（uint256 最大值，不丢位）
```

### 十进制 → wei（toWei）

```php
Units::toWei('1.5', 'ether');          // '1500000000000000000'
Units::toWei('1', 'gwei');             // '1000000000'
Units::toWei('0.000000001', 'ether');  // '1000000000'（恰好 1 gwei）
```

**超出目标精度时的行为**（安全设计，防静默丢钱）：

```php
Units::toWei('1.234', 2);                              // 抛 UnitsException（默认 ROUND_REJECT）
Units::toWei('1.234', 2, Units::ROUND_DOWN);           // '123'（向下截断）
Units::toWei('1.231', 2, Units::ROUND_UP);             // '124'（向上进位）
Units::toWei('1.9999999999', 'gwei', Units::ROUND_UP); // '2000000000'（进位冲到整数部分）
```

### 通用双向转换（convert）

```php
Units::convert('1.5', 'ether', 'gwei');       // '1500000000'
Units::convert('1000000000', 'wei', 'ether'); // '0.000000001'
```

### 支持的单位

| 单位 | 小数位 | 单位 | 小数位 |
|---|---|---|---|
| wei | 0 | szabo | 12 |
| kwei（babbage） | 3 | finney | 15 |
| mwei（lovelace） | 6 | ether | 18 |
| gwei（shannon） | 9 | kether（grand） | 21 |
| | | mether / gether / tether | 24 / 27 / 30 |

单位名不区分大小写，支持括号里的历史别名；也可以直接传 int 表示自定义小数位（如 ERC-20 的 `6` / `8`）。

### ERC-20 代币快捷转换

ERC-20 合约返回的 `balanceOf` / `decimals` 场景（USDT 是 6 位、WBTC 是 8 位，不要写死 18）：

```php
$decimals = 6; // 来自 token.decimals()
Units::tokenFromBase('123456', $decimals); // '0.123456'（最小单位 → 可读数量）
Units::tokenToBase('0.123456', $decimals); // '123456'（可读数量 → 最小单位，用于构造 calldata）
```

### 典型链上查询组合

```php
$balanceHex = $web3->eth->getBalance($address);       // '0x...' quantity
echo Units::fromWei($balanceHex, 'ether'), PHP_EOL;   // 直接得到 ETH 数量

$raw = $token->call('balanceOf', $address)[0];        // ERC-20 最小单位
echo Units::tokenFromBase($raw, $tokenDecimals), PHP_EOL;
```

完整可运行示例见 [`examples/units.php`](examples/units.php)：第 1–4 节离线可跑（单位换算、精度模式、ERC-20 往返），第 5 节真实连 BSC 主网把 `getBalance` / `gasPrice` / USDT `balanceOf` 结果换算成可读数量。

### 设计约束

- **拒绝浮点数**：传 `float` 直接抛异常（`0.1` 在二进制浮点里不精确，在 wei 量级下误差足以算错资产），请传字符串 `'1.5'`。
- **拒绝科学计数法**：`'1e18'` 无法无歧义地映射到精确整数，直接抛 `UnitsException`。
- 所有方法返回**字符串**（不是 int / float），拿到的就是能直接放进 calldata 或数据库的精确值。
- 异常类型：`Web3php\Exception\UnitsException`（继承自 `Web3Exception`）。

## 错误语义

- 传输层问题（连接失败 / 超时 / HTTP 非 200 / 非 JSON / 空响应）**不直接抛**：先在当前节点退避重试，耗尽后切换下一个节点，全部失败才抛 `RpcException`（message 汇总每个节点的失败原因）。
- RPC 业务错误（`execution reverted`、参数错误等）**不盲目重试**（重试也必然失败），立即抛 `RpcException`，带 `rpcCode` / `rpcData`。
- `estimateGas` 与交易发送（`sendTransaction` / 合约 `send` / `sendRawTransaction`）是特例：链上业务报错属于「业务答案」而非故障，不抛异常——estimateGas 直接返回报错文本（`ctype_digit()` 区分估值与报错），交易发送返回 `['ok' => false, 'hash' => null, 'error' => …]`（错误状态 + 提示语）；只有节点不可达才抛 `RpcException`。
- `eth_getTransactionReceipt` 未上链返回 `null` —— pending 不是错误。
- `failover_error_codes`：个别公共节点对特定查询返回错误而别的节点查得到（例如部分节点查久远回执返回 `-32602`），把这类错误码配进来会自动换节点再查。

## 测试与验证

- PHPUnit 全离线：EIP-155 官方 worked example 逐字节复现、EIP-55 官方向量、RLP 官方向量、ERC20 选择子、ABI 全类型编解码往返、struct 数组长度语义、截断检测、EIP-1559 签名恢复闭环、交易 input data 与事件日志解码（含 ERC20 Transfer topic0 世界锚点）、单位精度转换（wei/ether/gwei 往返、uint256 最大值、ROUND_DOWN/ROUND_UP、别名与非法输入）。
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
├── Abi/                    ABI 解析与编解码（ParamType/Abi/Method/Event/Encoder/Decoder/RawDecoder）
├── Tx/                     RLP / 签名器（EIP-155、EIP-1559）/ 交易构建
├── Utils/                  Hex / Address(EIP-55) / Crypto(Keccak) / Units(单位精度转换)
└── Exception/              异常体系
```

## 已知边界

- `fixed` / `ufixed` 类型未实现（极少有合约使用，遇到会抛出明确异常）。
- 无 ABI 的尽力解析只覆盖内置常见签名（选择子 / topic0 常量表），长尾方法或事件需要外部签名库（4byte.directory、openchain）或合约 ABI。
- 只支持 HTTP(S) RPC 端点（WebSocket 不支持）。

## License

MIT
