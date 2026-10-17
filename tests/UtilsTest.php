<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Utils\Address;
use Web3php\Utils\Crypto;
use Web3php\Utils\Hex;

final class UtilsTest extends TestCase
{
    /**
     * EIP-55 官方测试向量。
     */
    public function testChecksumAddress(): void
    {
        $vectors = [
            '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
            '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
            '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
            '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
            '0x52908400098527886E0F7030069857D2E4169EE7',
            '0x8617E340B3D01FA5F11F306F4090FD50E238070D',
        ];
        foreach ($vectors as $vector) {
            $this->assertSame($vector, Address::toChecksum(strtolower($vector)));
            $this->assertSame($vector, Address::toChecksum($vector));
        }
    }

    public function testRejectsInvalidAddress(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        Address::toChecksum('0x123');
    }

    public function testKeccakOfEmptyString(): void
    {
        // c5d246... 是空串 Keccak-256 的公认值
        $this->assertSame(
            '0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470',
            Crypto::keccakBin(''),
        );
    }

    /**
     * 全网烧录的 ERC20 选择子。
     */
    public function testKnownSelectors(): void
    {
        $this->assertSame('0xa9059cbb', Crypto::selector('transfer(address,uint256)'));
        $this->assertSame('0x70a08231', Crypto::selector('balanceOf(address)'));
        $this->assertSame('0x095ea7b3', Crypto::selector('approve(address,uint256)'));
        $this->assertSame('0x23b872dd', Crypto::selector('transferFrom(address,address,uint256)'));
        $this->assertSame('0x18160ddd', Crypto::selector('totalSupply()'));
    }

    public function testHexConversions(): void
    {
        $this->assertSame('0', Hex::toDec('0x'));
        $this->assertSame('0', Hex::toDec('0x0'));
        $this->assertSame('255', Hex::toDec('0xff'));
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            Hex::toDec('0xffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'),
        );

        $this->assertSame('0x0', Hex::decToHex('0'));
        $this->assertSame('0xff', Hex::decToHex('255'));
        $this->assertSame('0xde0b6b3a7640000', Hex::decToHex('1000000000000000000'));
        $this->assertSame(
            '0x00000000000000000000000000000000000000000000000000000000000000ff',
            Hex::decToHex('255', 32),
        );

        // 大数走 0x hex 也能转
        $this->assertSame('1000000000000000000', Hex::toDec('0xde0b6b3a7640000'));
    }

    public function testOddHexRejected(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        Hex::toBin('0xabc');
    }

    public function testFloatRejected(): void
    {
        $this->expectException(\Web3php\Exception\AbiException::class);
        Hex::toGmp(1.5);
    }
}
