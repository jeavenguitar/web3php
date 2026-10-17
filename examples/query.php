<?php

/**
 * 查询示例：
 *   1. 链基础数据（区块 / chainId / gasPrice）
 *   2. 主币（原生币）查询 —— BNB 余额 + nonce
 *   3. 合约查询（BSC USDT，ABI 自动解码）
 *   4. 批量查询
 *   5. 换条链演示 —— 以太坊主网查 ETH 余额
 *
 * 运行：composer install 后 php examples/query.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Web3php\Web3;

// ── 初始化：推荐传多个节点，某个抖动/挂掉时自动切换 ──────────────────
// 第一个故意放一个连不上的地址，演示故障转移（实际项目请删掉）
$web3 = new Web3([
    'https://rpc.dead-end.example.invalid',        // 故意的坏节点：自动跳到下一个
    'https://bsc-dataseed.bnbchain.org',
    'https://bsc-dataseed1.defibit.io',
], [
    'timeout' => 30,
    'retries' => 2,
    'retry_delay_ms' => 200,
]);

$eth = $web3->eth();

// ── 1. 链基础数据 ─────────────────────────────────────────
printf("当前区块高度：%d\n", $eth->blockNumber());
printf("chainId：%d（BSC 主网应为 56）\n", $eth->chainId());
printf("gasPrice：%s wei\n", $eth->gasPrice());

// ── 2. 主币（BNB）查询 ─────────────────────────────────────
$someone = '0xd1A436b493777Ce2f4119c6AcB3207667AA3b1Ec';

// 余额：wei 十进制字符串（18 位小数链上 1 BNB = 10^18 wei）
$wei = $eth->getBalance($someone);
printf("\n主币查询（BNB）\n  地址：%s\n  余额：%s wei = %.8f BNB\n", $someone, $wei, bcdiv($wei, bcpow('10', '18'), 8));

// 该地址历史交易数（发交易取 nonce 就是这个值）
printf("  交易数（nonce 口径）：%d\n", $eth->getTransactionCount($someone));

// ── 3. 合约查询：引入 ABI，返回值自动解码 ──────────────────────
$erc20Abi = <<<'JSON'
[
  {"type":"function","name":"name","inputs":[],"outputs":[{"name":"","type":"string"}],"stateMutability":"view"},
  {"type":"function","name":"symbol","inputs":[],"outputs":[{"name":"","type":"string"}],"stateMutability":"view"},
  {"type":"function","name":"decimals","inputs":[],"outputs":[{"name":"","type":"uint8"}],"stateMutability":"view"},
  {"type":"function","name":"totalSupply","inputs":[],"outputs":[{"name":"","type":"uint256"}],"stateMutability":"view"},
  {"type":"function","name":"balanceOf","inputs":[{"name":"account","type":"address"}],"outputs":[{"name":"","type":"uint256"}],"stateMutability":"view"}
]
JSON;

// BSC 主网 USDT
$usdt = $web3->contract($erc20Abi)->at('0x55d398326f99059fF775485246999027B3197955');

// 单输出方法：直接拿到解码后的标量
printf("\n代币名称：%s（%s），精度 %s 位\n", $usdt->call('name'), $usdt->call('symbol'), $usdt->call('decimals'));
printf("总供应量：%s（最小单位）\n", $usdt->call('totalSupply'));

// 带参查询：大数返回十进制字符串，不丢精度
$balance = $usdt->call('balanceOf', $someone);
printf("%s 的 USDT 余额：%s（最小单位）\n", $someone, $balance);
printf("换算成代币：%.4f USDT\n", bcdiv($balance, bcpow('10', '18'), 4));

// ── 4. 批量查询：一次 HTTP 往返查多个（余额列表场景常用）──────────────
$holders = [
    '0x8894E0a0c962CB723c1976a4421c95949bE2D4E3',
    '0xD1B8d52D397C47D628CbAE7a3982fC0a5F5B7F3f',
    $someone,
];
$calls = array_map(fn ($addr) => ['method' => 'eth_getBalance', 'params' => [$addr, 'latest']], $holders);
$results = $web3->rpc()->batch($calls);
foreach ($holders as $i => $holder) {
    if ($results[$i] instanceof \Web3php\Exception\RpcException) {
        printf("批次第 %d 条失败：%s\n", $i, $results[$i]->getMessage());
    } else {
        printf("批次余额 %s：%s wei\n", $holder, \Web3php\Utils\Hex::toDec($results[$i]));
    }
}

// ── 5. 换条链也一样：以太坊主网查 ETH 余额 ─────────────────────
// 换个 RPC 端点就是另一条链，API 完全一致
$mainnet = new Web3([
    'https://eth.llamarpc.com',
    'https://ethereum-rpc.publicnode.com',
], ['timeout' => 30, 'retries' => 2]);

try {
    $vitalik = '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045';
    $ethWei = $mainnet->eth()->getBalance($vitalik);
    printf("\n换链演示（ETH）\n  %s 在以太坊主网：\n  %s wei = %.8f ETH（区块 %d）\n",
        $vitalik, $ethWei, bcdiv($ethWei, bcpow('10', '18'), 8), $mainnet->eth()->blockNumber());
} catch (\Web3php\Exception\RpcException $e) {
    // 演示一下错误长什么样：message 汇总了每个节点的失败原因
    printf("\n换链演示（ETH）查询失败：%s\n", $e->getMessage());
}

// ── 命中的节点（坏节点被跳过后粘住这个节点复用长连接）─────────────
printf("\n实际命中节点：%s\n", $web3->rpc()->lastEndpoint());
