<?php

/**
 * 发送交易示例（本地签名 + 广播 + 等回执）：
 *   1. 主币转账 —— BNB / ETH 等 EVM 链通用
 *   2. ERC20 转账
 *
 * 私钥从环境变量读取，避免写进代码：
 *   export PRIVATE_KEY=0x...（不要提交任何含私钥的文件）
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Web3php\Eth;
use Web3php\Web3;

$privateKey = getenv('PRIVATE_KEY');
if (!$privateKey) {
    exit("请先 export PRIVATE_KEY=0x...\n");
}

// BSC 主网（发 ETH 只需换端点，例如 https://eth.llamarpc.com）
$web3 = new Web3(['https://bsc-dataseed.bnbchain.org'], ['timeout' => 30]);
$wallet = $web3->wallet($privateKey);
printf("发送方：%s\n", $wallet->address());

// 广播后统一等回执（BSC 出块约 3 秒；超时返回 null 不代表失败）
$waitForReceipt = fn (string $hash) => $web3->eth()->waitForReceipt($hash, 60.0, 3000);

$report = function (string $title, string $hash) use ($waitForReceipt): void {
    printf("%s 已广播：%s\n浏览器：https://bscscan.com/tx/%s\n", $title, $hash, $hash);
    $receipt = $waitForReceipt($hash);
    if ($receipt === null) {
        printf("60 秒内未上链，稍后用 eth_getTransactionReceipt 复查\n");

        return;
    }
    printf("区块 %s，状态：%s，gas 使用 %s\n",
        $receipt->blockNumber,
        Eth::isSuccessful($receipt) ? '成功' : '失败（reverted）',
        \Web3php\Utils\Hex::toDec($receipt->gasUsed),
    );
};

// ════════════════════════════════════════════════════════════
// 1. 主币转账（BNB；发 ETH 完全同理）
// ════════════════════════════════════════════════════════════
$payee = '0x接收地址';
$amount = '10000000000000000'; // 0.01 BNB（wei = 10^18 进制，大数一律用字符串）

// 发送前自查余额是否够付（余额 + gas 成本）
$balance = $web3->eth()->getBalance($wallet->address());
$gasPrice = $web3->eth()->gasPrice();
$need = bcadd($amount, bcmul('21000', $gasPrice)); // 普通转账固定 21000 gas
printf("\n[主币转账] 当前余额 %s wei，本笔约需 %s wei（含 gas）%s\n",
    $balance, $need, bccomp($balance, $need) >= 0 ? '→ 够' : '→ 不够，别发！');

// 最简形态：只给 to + value；nonce / gas / gasPrice / chainId 全部自动补齐
$txHash = $web3->sendTransaction($wallet, [
    'to' => $payee,
    'value' => $amount,
    // 可选手动覆盖：
    // 'gas' => '21000',
    // 'gasPrice' => '1000000000',
    // 'maxFeePerGas' => '…', 'maxPriorityFeePerGas' => '…',  // 给了 maxFeePerGas 即 EIP-1559
    // 'nonce' => 123,
]);

$report('[主币转账]', $txHash);

// ════════════════════════════════════════════════════════════
// 2. ERC20 转账（合约调用）
// ════════════════════════════════════════════════════════════
$erc20Abi = <<<'JSON'
[
  {"type":"function","name":"transfer","inputs":[{"name":"to","type":"address"},{"name":"amount","type":"uint256"}],"outputs":[{"name":"","type":"bool"}],"stateMutability":"nonpayable"}
]
JSON;

$token = $web3->contract($erc20Abi)->at('0x55d398326f99059fF775485246999027B3197955'); // BSC USDT

$to = '0x接收地址';
$tokenAmount = '1000000000000000000'; // 1 USDT（18 位精度）

// send() 自动补 nonce / gas / gasPrice / chainId，本地签名后广播；
// 余额不足或合约 revert 时抛异常（revert 原因会自动解出）
$txHash = $token->send('transfer', [$to, $tokenAmount], $wallet, [
    // 'gas' => '65000',
    // 'gasPrice' => '5000000000',
    // 'maxFeePerGas' => '…',  // EIP-1559
]);

$report('[ERC20]', $txHash);
