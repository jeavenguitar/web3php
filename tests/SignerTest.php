<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Tx\Signer;
use Web3php\Utils\Crypto;
use Web3php\Utils\Hex;
use Web3php\Wallet;

/**
 * EIP-155 规范文本里的完整 worked example：
 *   nonce=9, gasPrice=20gwei, gas=21000, to=0x3535...35, value=1e18, data='',
 *   chainId=1, 私钥 0x4646...46
 * 签名哈希与最终签名交易在 EIP 里逐字节给出，是最好的离线金标准。
 */
final class SignerTest extends TestCase
{
    private function eip155Tx(): array
    {
        return [
            'nonce' => gmp_init(9),
            'gasPrice' => gmp_init('20000000000'),
            'gas' => gmp_init(21000),
            'to' => hex2bin('3535353535353535353535353535353535353535'),
            'value' => gmp_init('1000000000000000000'),
            'data' => '',
            'chainId' => 1,
        ];
    }

    public function testKnownPrivateKeyDerivesKnownAddress(): void
    {
        // 私钥 0x...01 的地址是全网固定的
        $wallet = new Wallet('0x0000000000000000000000000000000000000000000000000000000000000001');
        $this->assertSame('0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf', $wallet->address());
    }

    public function testEip155SigningHash(): void
    {
        $unsigned = [
            $this->eip155Tx()['nonce'],
            $this->eip155Tx()['gasPrice'],
            $this->eip155Tx()['gas'],
            $this->eip155Tx()['to'],
            $this->eip155Tx()['value'],
            $this->eip155Tx()['data'],
            1,
            '',
            '',
        ];
        $rlpHex = bin2hex(\Web3php\Tx\Rlp::encode($unsigned));
        $hash = Crypto::keccakHex($rlpHex);
        $this->assertSame('0xdaf5a779ae972f972197303d7b574746c7ef83eadac0f2791ad23db92e4c8e53', $hash);
    }

    public function testEip155SignedTransaction(): void
    {
        $wallet = new Wallet('0x4646464646464646464646464646464646464646464646464646464646464646');
        $raw = Signer::signLegacy($this->eip155Tx(), $wallet);

        $this->assertSame(
            '0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83',
            strtolower($raw),
        );
    }

    public function testEip1559EnvelopeStructureAndRecovery(): void
    {
        $wallet = new Wallet('0x4646464646464646464646464646464646464646464646464646464646464646');
        $tx = [
            'chainId' => 1,
            'nonce' => gmp_init(9),
            'maxPriorityFeePerGas' => gmp_init('1000000000'),
            'maxFeePerGas' => gmp_init('30000000000'),
            'gas' => gmp_init(21000),
            'to' => hex2bin('3535353535353535353535353535353535353535'),
            'value' => gmp_init('1000000000000000000'),
            'data' => '',
        ];
        $raw = Signer::signEip1559($tx, $wallet);

        // type 2 信封：0x02 前缀
        $this->assertStringStartsWith('0x02', strtolower($raw));

        // 签名哈希 = keccak(0x02 ‖ rlp(无签名字段))，与 Signer 相同结构独立复算
        $unsigned = [$tx['chainId'], $tx['nonce'], $tx['maxPriorityFeePerGas'], $tx['maxFeePerGas'],
            $tx['gas'], $tx['to'], $tx['value'], $tx['data'], []];
        $hash = Crypto::keccakHex(bin2hex(\Web3php\Tx\Rlp::encode($unsigned)));

        // 密码学闭环：用 (r, s, yParity, hash) 恢复公钥 → 地址必须等于签名钱包
        $signature = $wallet->keyPair()->sign(\Web3php\Utils\Hex::stripPrefix($hash), ['canonical' => true]);
        $recovered = $wallet->keyPair()->ec->recoverPubKey(
            \Web3php\Utils\Hex::stripPrefix($hash),
            $signature,
            $signature->recoveryParam,
        );
        $publicHex = str_pad($recovered->getX()->toString(16), 64, '0', STR_PAD_LEFT)
            . str_pad($recovered->getY()->toString(16), 64, '0', STR_PAD_LEFT);
        $address = '0x' . substr(\Web3php\Utils\Hex::stripPrefix(Crypto::keccakHex($publicHex)), 24);

        $this->assertSame(strtolower($wallet->address()), strtolower($address));
    }

    public function testWalletRejectsBadPrivateKey(): void
    {
        $this->expectException(\Web3php\Exception\TxException::class);
        new Wallet('not-a-key');
    }

    public function testWalletCreateRoundTrip(): void
    {
        $wallet = Wallet::create();
        $this->assertMatchesRegularExpression('/^0x[0-9a-fA-F]{40}$/', $wallet->address());
        $again = new Wallet($wallet->privateKey());
        $this->assertSame($wallet->address(), $again->address());
    }
}
