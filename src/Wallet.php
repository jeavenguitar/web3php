<?php

declare(strict_types=1);

namespace Web3php;

use Elliptic\EC;
use Web3php\Exception\TxException;
use Web3php\Utils\Address;
use Web3php\Utils\Crypto;
use Web3php\Utils\Hex;

/**
 * 私钥钱包：派生 EIP-55 地址、提供 secp256k1 签名能力。
 */
final class Wallet
{
    private string $privateKeyHex;

    private EC\KeyPair $keyPair;

    private ?string $address = null;

    public function __construct(string $privateKey)
    {
        $hex = strtolower(trim($privateKey));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new TxException('私钥必须是 64 位 hex 字符串（32 字节，0x 前缀可选）');
        }

        $this->privateKeyHex = $hex;
        $this->keyPair = (new EC('secp256k1'))->keyFromPrivate($hex, 'hex');
    }

    /**
     * 随机创建一个新钱包（密码学安全随机源）。
     */
    public static function create(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    /**
     * 64 位 hex 私钥（无 0x）。注意保密。
     */
    public function privateKey(): string
    {
        return $this->privateKeyHex;
    }

    /**
     * EIP-55 校验和地址。
     */
    public function address(): string
    {
        if ($this->address === null) {
            $public = $this->keyPair->getPublic('hex');
            if (str_starts_with($public, '04')) {
                $public = substr($public, 2);
            }
            $hash = Crypto::keccakBin(hex2bin($public));
            $this->address = Address::toChecksum('0x' . substr(Hex::stripPrefix($hash), 24));
        }

        return $this->address;
    }

    /**
     * @internal 仅供 Signer 签名使用
     */
    public function keyPair(): EC\KeyPair
    {
        return $this->keyPair;
    }
}
