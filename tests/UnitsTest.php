<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Exception\UnitsException;
use Web3php\Utils\Units;

final class UnitsTest extends TestCase
{
    public function testFromWeiEther(): void
    {
        $this->assertSame('1', Units::fromWei('1000000000000000000', 'ether'));
        $this->assertSame('1.5', Units::fromWei('1500000000000000000', 'ether'));
        $this->assertSame('1.234567890123456789', Units::fromWei('1234567890123456789', 'ether'));
        $this->assertSame('0.000000000000000001', Units::fromWei('1', 'ether'));
        $this->assertSame('0', Units::fromWei('0', 'ether'));
    }

    public function testFromWeiGwei(): void
    {
        $this->assertSame('1', Units::fromWei('1000000000', 'gwei'));
        $this->assertSame('20.5', Units::fromWei('20500000000', 'gwei'));
    }

    public function testFromWeiSmallUnits(): void
    {
        $this->assertSame('1', Units::fromWei('1', 'wei'));
        $this->assertSame('1', Units::fromWei('1000', 'kwei'));
        $this->assertSame('1', Units::fromWei('1000000000000000000000', 'kether'));
    }

    public function testFromWeiLeadingZeroPadding(): void
    {
        // 999 wei → 不足 18 位，需前导补零
        $this->assertSame('0.000000000000000999', Units::fromWei('999', 'ether'));
        // 尾部 0 去除
        $this->assertSame('0.1', Units::fromWei('100000000000000000', 'ether'));
        $this->assertSame('0.01', Units::fromWei('10000000000000000', 'ether'));
    }

    public function testFromWeiNegative(): void
    {
        $this->assertSame('-1.5', Units::fromWei('-1500000000000000000', 'ether'));
        $this->assertSame('-999', Units::fromWei('-999', 'wei'));
    }

    public function testFromWeiCustomDecimals(): void
    {
        $this->assertSame('0.123456', Units::fromWei('123456', 6));
        $this->assertSame('0.12345678', Units::fromWei('12345678', 8));
        $this->assertSame('123456', Units::fromWei('123456', 0));
    }

    public function testFromWeiHexInput(): void
    {
        // 0xde0b6b3a7640000 = 10^18
        $this->assertSame('1', Units::fromWei('0xde0b6b3a7640000', 'ether'));
    }

    public function testFromWeiUint256Max(): void
    {
        $max = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
        $this->assertSame(
            '115792089237316195423570985008687907853269984665640564039457.584007913129639935',
            Units::fromWei($max, 'ether')
        );
    }

    public function testToWei(): void
    {
        $this->assertSame('1000000000000000000', Units::toWei('1', 'ether'));
        $this->assertSame('1500000000000000000', Units::toWei('1.5', 'ether'));
        $this->assertSame('1000000000', Units::toWei('0.000000001', 'ether', Units::ROUND_DOWN)); // 1 gwei
        $this->assertSame('1000000000', Units::toWei('1', 'gwei'));
        $this->assertSame('1', Units::toWei('1', 'wei'));
        $this->assertSame('0', Units::toWei('0', 'ether'));
        $this->assertSame('1000000000000000000', Units::toWei(1, 'ether'));
    }

    public function testToWeiRoundTrip(): void
    {
        $this->assertSame('1234567890123456789', Units::toWei(Units::fromWei('1234567890123456789', 'ether'), 'ether'));
        $this->assertSame('999', Units::toWei(Units::fromWei('999', 'ether'), 'ether'));
    }

    public function testToWeiNegative(): void
    {
        $this->assertSame('-1500000000000000000', Units::toWei('-1.5', 'ether'));
    }

    public function testToWeiCustomDecimals(): void
    {
        $this->assertSame('123456', Units::tokenToBase('0.123456', 6));
        $this->assertSame('12345678', Units::tokenToBase('0.12345678', 8));
    }

    public function testToWeiExceedsPrecisionRejects(): void
    {
        $this->expectException(UnitsException::class);
        Units::toWei('1.234', 2);
    }

    public function testToWeiRoundDown(): void
    {
        $this->assertSame('123', Units::toWei('1.234', 2, Units::ROUND_DOWN));
        $this->assertSame('1999999999', Units::toWei('1.9999999999', 'gwei', Units::ROUND_DOWN));
    }

    public function testToWeiRoundUp(): void
    {
        $this->assertSame('124', Units::toWei('1.231', 2, Units::ROUND_UP));
        $this->assertSame('2000000000', Units::toWei('1.9999999999', 'gwei', Units::ROUND_UP));
        // 仅有尾零（rest 全为 0）不进位
        $this->assertSame('12', Units::toWei('1.20', 1, Units::ROUND_UP));    }

    public function testConvert(): void
    {
        $this->assertSame('1500000000000000000', Units::convert('1.5', 'ether', 'wei'));
        $this->assertSame('1', Units::convert('1000000000000000000', 'wei', 'ether'));
        $this->assertSame('1500000000', Units::convert('1.5', 'ether', 'gwei'));
        $this->assertSame('1000', Units::convert('1000000', 'wei', 'kwei'));
    }

    public function testUnitAliases(): void
    {
        $this->assertSame('1000000000', Units::convert('1', 'shannon', 'wei'));
        $this->assertSame('1000', Units::convert('1', 'babbage', 'wei'));
        $this->assertSame('1000000', Units::convert('1', 'lovelace', 'wei'));
        $this->assertSame('1000000000000000000000', Units::convert('1', 'grand', 'wei'));
    }

    public function testCaseInsensitiveUnits(): void
    {
        $this->assertSame('1000000000000000000', Units::toWei('1', 'ETHER'));
        $this->assertSame('1000000000', Units::toWei('1', 'Gwei'));
    }

    public function testUnknownUnitThrows(): void
    {
        $this->expectException(UnitsException::class);
        $this->expectExceptionMessage('未知单位');
        Units::fromWei('1', 'bitcoin');
    }

    public function testInvalidAmountThrows(): void
    {
        $this->expectException(UnitsException::class);
        Units::toWei('1.2.3', 'ether');
    }

    public function testEmptyAmountThrows(): void
    {
        $this->expectException(UnitsException::class);
        Units::toWei('', 'ether');
    }

    public function testFloatRejected(): void
    {
        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line 故意传 float 测试拒绝
        Units::toWei(1.5, 'ether');
    }

    public function testScientificNotationRejected(): void
    {
        $this->expectException(UnitsException::class);
        Units::toWei('1e18', 'ether');
    }
}
