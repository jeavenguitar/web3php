<?php

/**
 * 单位与精度转换示例（Units 工具类）：
 *   1. wei → 十进制（含 RPC 返回的 hex quantity 直接转换）
 *   2. 十进制 → wei（含超精度时的 ROUND_REJECT / ROUND_DOWN / ROUND_UP）
 *   3. 通用双向转换 convert（任意单位之间）
 *   4. ERC-20 代币场景（USDT 6 位精度，balanceOf 结果 → 可读数量 → calldata 金额）
 *   5. 与链上查询组合使用（真实查 BSC 主网 BNB 余额并换算）
 *
 * 运行：composer install 后 php examples/units.php（第 5 节需要网络，其余离线可跑）
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Web3php\Exception\UnitsException;
use Web3php\Utils\Units;

// ── 1. wei → 十进制（fromWei）──────────────────────────────
echo "── 1. wei → 十进制 ──────────────────────────────\n";

echo Units::fromWei('1000000000000000000', 'ether'), "\n";        // 1
echo Units::fromWei('1234567890123456789', 'ether'), "\n";        // 1.234567890123456789（18 位小数原样保留）
echo Units::fromWei('1500000000000000000', 'ether'), "\n";        // 1.5（尾部 0 自动去除）
echo Units::fromWei('20500000000', 'gwei'), "\n";                 // 20.5
echo Units::fromWei('0xde0b6b3a7640000', 'ether'), "\n";          // 1（hex quantity 直接可用）
echo Units::fromWei('999', 'ether'), "\n";                        // 0.000000000000000999（前导补零）
echo Units::fromWei('115792089237316195423570985008687907853269984665640564039457584007913129639935', 'ether'), "\n";
// uint256 最大值 → 115792089237316195423570985008687907853269984665640564039457.584007913129639935，不丢一位

// ── 2. 十进制 → wei（toWei）────────────────────────────────
echo "\n── 2. 十进制 → wei ──────────────────────────────\n";

echo Units::toWei('1.5', 'ether'), "\n";                          // 1500000000000000000
echo Units::toWei('1', 'gwei'), "\n";                             // 1000000000
echo Units::toWei('0.000000001', 'ether'), "\n";                  // 1000000000（恰好 1 gwei）

// 超出目标精度：默认直接抛错（防静默丢钱），显式选择模式才能截断/进位
try {
    Units::toWei('1.234', 2);
} catch (UnitsException $e) {
    echo '默认拒绝：', $e->getMessage(), "\n";
}
echo Units::toWei('1.234', 2, Units::ROUND_DOWN), "\n";           // 123（向下截断）
echo Units::toWei('1.231', 2, Units::ROUND_UP), "\n";             // 124（向上进位）
echo Units::toWei('1.9999999999', 'gwei', Units::ROUND_UP), "\n"; // 2000000000（进位冲到整数部分）

// ── 3. 通用双向转换（convert）──────────────────────────────
echo "\n── 3. 任意单位互转（convert）─────────────────────\n";

echo Units::convert('1.5', 'ether', 'gwei'), "\n";                // 1500000000
echo Units::convert('1000000000', 'wei', 'ether'), "\n";          // 0.000000001
echo Units::convert('1', 'shannon', 'wei'), "\n";                 // 1000000000（历史别名也能识别）

// ── 4. ERC-20 代币场景 ─────────────────────────────────────
echo "\n── 4. ERC-20（USDT，6 位精度）────────────────────\n";

$usdtDecimals = 6; // 来自 $token->call('decimals')，不同代币不同：USDT=6、WBTC=8、多数新币=18
$balanceRaw = '123456789';                                        // 合约返回的最小单位数量

$readable = Units::tokenFromBase($balanceRaw, $usdtDecimals);
printf("余额：%s 最小单位 = %s USDT\n", $balanceRaw, $readable);   // 123.456789 USDT

$callAmount = Units::tokenToBase('12.345678', $usdtDecimals);
printf("要转 12.345678 USDT → calldata 金额 %s\n", $callAmount);   // 12345678

// 往返一致（GMP 大整数保证，任何数额都不丢位）
echo Units::tokenToBase(Units::tokenFromBase($balanceRaw, $usdtDecimals), $usdtDecimals), "（往返一致）\n";

// ── 5. 与链上查询组合（需要网络）───────────────────────────
echo "\n── 5. 链上查询组合（BSC 主网）────────────────────\n";

$web3 = new Web3php\Web3(['https://bsc-dataseed.bnbchain.org'], ['timeout' => 30]);

$address = '0xd1A436b493777Ce2f4119c6AcB3207667AA3b1Ec';

// 主币：RPC 返回 hex quantity → 直接换算成 BNB
$wei = $web3->eth()->getBalance($address);
printf("BNB 余额：%s wei = %s BNB\n", $wei, Units::fromWei($wei, 'ether'));

// gasPrice（wei）→ gwei，看当前链上费率更直观
$gasPrice = $web3->eth()->gasPrice();
printf("gasPrice：%s wei = %s gwei\n", $gasPrice, Units::fromWei($gasPrice, 'gwei'));

// ERC-20：USDT 余额（ABI 自动解码）→ 可读数量
$erc20Abi = [
    ['constant' => true, 'inputs' => [['name' => '_owner', 'type' => 'address']], 'name' => 'balanceOf',
     'outputs' => [['name' => '', 'type' => 'uint256']], 'type' => 'function'],
    ['constant' => true, 'inputs' => [], 'name' => 'decimals',
     'outputs' => [['name' => '', 'type' => 'uint8']], 'type' => 'function'],
];
$token = $web3->contract($erc20Abi)->at('0x55d398326f99059fF775485246999027B3197955'); // BSC USDT
$decimals = (int) $token->call('decimals')[0];                    // 6
$usdtRaw  = $token->call('balanceOf', $address)[0];
printf("USDT 余额：%s = %s USDT\n", (string) $usdtRaw, Units::tokenFromBase($usdtRaw, $decimals));
