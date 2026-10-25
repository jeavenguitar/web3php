<?php

declare(strict_types=1);

namespace Web3php\Tests;

use PHPUnit\Framework\TestCase;
use Web3php\Abi\Encoder;
use Web3php\Abi\ParamType;
use Web3php\Abi\RawDecoder;
use Web3php\Contract\Contract;
use Web3php\Exception\AbiException;
use Web3php\Utils\Address;
use Web3php\Utils\Crypto;
use Web3php\Utils\Hex;
use Web3php\Web3;

/**
 * 交易 input data 与事件日志解析：
 *   - 有 ABI：Contract::decodeInput（选择子反查）、decodeLogs（topic0 反查）
 *   - 无 ABI：RawDecoder 内置常见签名尽力识别 + 32 字节字切分
 */
final class TxDecodeTest extends TestCase
{
    private const FROM = '0x7E5F4552091A69125d5DfCb7b8C2659029395Bdf';
    private string $to;

    protected function setUp(): void
    {
        $this->to = Address::toChecksum('0x2b5ad5c4795c026514f8317c7a215e218dccd6cf');
    }

    private function token(): Contract
    {
        $abi = <<<'JSON'
        [
          {"type":"function","name":"transfer","inputs":[{"name":"to","type":"address"},{"name":"amount","type":"uint256"}],"outputs":[{"name":"","type":"bool"}],"stateMutability":"nonpayable"},
          {"type":"event","name":"Transfer","inputs":[{"name":"from","type":"address","indexed":true},{"name":"to","type":"address","indexed":true},{"name":"value","type":"uint256","indexed":false}],"anonymous":false},
          {"type":"event","name":"Foo","inputs":[{"name":"tag","type":"string","indexed":true},{"name":"n","type":"uint256"}],"anonymous":false}
        ]
        JSON;
        $web3 = new Web3('http://127.0.0.1:8545'); // 仅解析，不联网

        return $web3->contract($abi)->at('0x55d398326f99059fF775485246999027B3197955');
    }

    private function topic0(string $eventSignature): string
    {
        return Crypto::keccakBin($eventSignature);
    }

    private function addressTopic(string $address): string
    {
        return '0x' . Hex::padLeft32(Hex::stripPrefix($address));
    }

    private function transferCalldata(): string
    {
        return '0xa9059cbb' . Encoder::encode(
            [ParamType::parse('address'), ParamType::parse('uint256')],
            [$this->to, '1000000'],
        );
    }

    private function transferLog(): object
    {
        return (object) [
            'address' => '0x55d398326f99059fF775485246999027B3197955',
            'topics' => [
                $this->topic0('Transfer(address,address,uint256)'),
                $this->addressTopic(self::FROM),
                $this->addressTopic($this->to),
            ],
            'data' => '0x' . Encoder::encode([ParamType::parse('uint256')], ['1000000']),
        ];
    }

    // —— 有 ABI：decodeInput ————————————————————————————————————

    public function testDecodeInputTransfer(): void
    {
        $token = $this->token();
        $calldata = $this->transferCalldata();

        // 与合约对象自身的编码器交叉验证
        $this->assertSame($calldata, $token->encode('transfer', [$this->to, '1000000']));

        $parsed = $token->decodeInput($calldata);
        $this->assertSame('transfer', $parsed['method']);
        $this->assertSame('transfer(address,uint256)', $parsed['signature']);
        $this->assertSame('0xa9059cbb', $parsed['selector']);
        $this->assertSame(['to' => $this->to, 'amount' => '1000000'], $parsed['args']);
    }

    public function testDecodeInputPlainNativeTransfer(): void
    {
        $this->assertSame(
            ['method' => null, 'signature' => null, 'selector' => null, 'args' => []],
            $this->token()->decodeInput('0x'),
        );
    }

    public function testDecodeInputUnknownSelectorThrows(): void
    {
        $this->expectException(AbiException::class);
        $this->expectExceptionMessage('不在 ABI 中');
        $this->token()->decodeInput('0xdeadbeef' . str_repeat('00', 64));
    }

    // —— 有 ABI：decodeLogs ————————————————————————————————————

    public function testDecodeLogsTransferEvent(): void
    {
        $decoded = $this->token()->decodeLogs([$this->transferLog()]);

        $this->assertCount(1, $decoded);
        $this->assertSame('Transfer', $decoded[0]['event']);
        $this->assertSame('0x55d398326f99059fF775485246999027B3197955', $decoded[0]['address']);
        $this->assertSame(['from' => self::FROM, 'to' => $this->to, 'value' => '1000000'], $decoded[0]['args']);
    }

    public function testDecodeLogsWithIndexedDynamicTypeReturnsHash(): void
    {
        $tagHash = Crypto::keccakBin('abc');
        $log = (object) [
            'address' => '0x55d398326f99059fF775485246999027B3197955',
            'topics' => [$this->topic0('Foo(string,uint256)'), $tagHash],
            'data' => '0x' . Encoder::encode([ParamType::parse('uint256')], ['7']),
        ];

        $decoded = $this->token()->decodeLogs([$log]);

        $this->assertSame('Foo', $decoded[0]['event']);
        // string indexed 只存 keccak 哈希，无法还原原文
        $this->assertSame(['tag' => $tagHash, 'n' => '7'], $decoded[0]['args']);
    }

    public function testDecodeLogsUnknownEventPassesThrough(): void
    {
        $log = (object) [
            'address' => '0x55d398326f99059fF775485246999027B3197955',
            'topics' => [$this->topic0('Whatever(uint256)')],
            'data' => '0x' . str_repeat('00', 64),
        ];

        $decoded = $this->token()->decodeLogs([$log]);

        $this->assertNull($decoded[0]['event']);
        $this->assertSame([], $decoded[0]['args']);
        $this->assertSame($log, $decoded[0]['log']);
    }

    // —— 无 ABI：RawDecoder ————————————————————————————————————

    public function testRawDecoderParsesCommonTransferCalldata(): void
    {
        $parsed = RawDecoder::parseInput($this->transferCalldata());

        $this->assertSame('0xa9059cbb', $parsed['selector']);
        $this->assertSame('transfer', $parsed['method']);
        $this->assertSame('transfer(address,uint256)', $parsed['signature']);
        $this->assertSame([0 => $this->to, 1 => '1000000'], $parsed['args']);
        $this->assertSame([], $parsed['words']);
    }

    public function testRawDecoderUnknownSelectorFallsBackToWords(): void
    {
        $parsed = RawDecoder::parseInput('0x12345678' . str_repeat('00', 128));

        $this->assertSame('0x12345678', $parsed['selector']);
        $this->assertNull($parsed['method']);
        $this->assertCount(4, $parsed['words']);
    }

    public function testRawDecoderParsesCommonTransferLog(): void
    {
        $parsed = RawDecoder::parseLog($this->transferLog());

        $this->assertSame('Transfer', $parsed['event']);
        $this->assertSame('Transfer(address,address,uint256)', $parsed['signature']);
        $this->assertSame([0 => self::FROM, 1 => $this->to, 2 => '1000000'], $parsed['args']);
        $this->assertCount(3, $parsed['topics']);
        $this->assertCount(1, $parsed['dataWords']);
    }

    public function testRawDecoderParseTransaction(): void
    {
        $tx = (object) [
            'from' => self::FROM,
            'to' => '0x55d398326f99059fF775485246999027B3197955',
            'value' => '0xde0b6b3a7640000',
            'input' => $this->transferCalldata(),
        ];

        $parsed = RawDecoder::parseTransaction($tx);

        $this->assertSame(self::FROM, $parsed['from']);
        $this->assertSame('1000000000000000000', $parsed['value']);
        $this->assertSame('transfer', $parsed['method']);
        $this->assertSame('1000000', $parsed['args'][1]);
    }

    // —— 常量表完整性 ————————————————————————————————————

    public function testCommonMethodTableMatchesKeccak(): void
    {
        foreach (RawDecoder::METHODS as $selector => [$name, $types]) {
            $signature = $name . '(' . implode(',', $types) . ')';
            $this->assertSame(
                $selector,
                Hex::stripPrefix(Crypto::selector($signature)),
                "常量表选择子与 keccak 不符：{$signature}",
            );
        }
    }

    public function testCommonEventTableMatchesKeccak(): void
    {
        foreach (RawDecoder::EVENTS as $topic0 => [$name, $indexed, $data]) {
            $signature = $name . '(' . implode(',', [...$indexed, ...$data]) . ')';
            $this->assertSame(
                $topic0,
                Hex::stripPrefix(Crypto::keccakBin($signature)),
                "常量表 topic0 与 keccak 不符：{$signature}",
            );
        }
    }

    public function testTransferEventTopic0MatchesWorldAnchor(): void
    {
        // ERC20 Transfer 事件哈希是全网固定值，锚定 keccak 实现与签名拼接都正确
        $this->assertSame(
            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
            Crypto::keccakBin('Transfer(address,address,uint256)'),
        );
    }
}
