<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace AnimeDb\Plugins\Tools\Tests;

use AnimeDb\Plugins\Tools\PluginRegistrySigner;
use PHPUnit\Framework\TestCase;

final class PluginRegistrySignerTest extends TestCase
{
    public function testVerifyAcceptsAGenuineSignature(): void
    {
        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();
        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';

        $signature = $signer->sign($message, $keyPair['secret']);

        self::assertTrue($signer->verify($message, $signature, $keyPair['public']));
    }

    public function testVerifyRejectsATamperedMessageByte(): void
    {
        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();
        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';

        $signature = $signer->sign($message, $keyPair['secret']);

        $tamperedMessage = substr_replace($message, '2', 12, 1); // flips the sequence digit
        self::assertNotSame($message, $tamperedMessage);

        self::assertFalse($signer->verify($tamperedMessage, $signature, $keyPair['public']));
    }

    public function testVerifyRejectsATamperedSignatureByte(): void
    {
        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();
        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';

        $signature = $signer->sign($message, $keyPair['secret']);
        $signatureBytes = base64_decode($signature, true);
        self::assertIsString($signatureBytes);
        $signatureBytes[0] = \chr((\ord($signatureBytes[0]) + 1) % 256);
        $tamperedSignature = base64_encode($signatureBytes);

        self::assertFalse($signer->verify($message, $tamperedSignature, $keyPair['public']));
    }

    public function testVerifyRejectsAWrongPublicKey(): void
    {
        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();
        $otherKeyPair = $signer->generateKeyPair();
        $message = '{"sequence":1,"asset_mirrors":[],"plugins":[]}';

        $signature = $signer->sign($message, $keyPair['secret']);

        self::assertFalse($signer->verify($message, $signature, $otherKeyPair['public']));
    }

    public function testVerifyRejectsMalformedBase64Input(): void
    {
        $signer = new PluginRegistrySigner();
        $keyPair = $signer->generateKeyPair();

        self::assertFalse($signer->verify('message', 'not-a-valid-signature', $keyPair['public']));
        self::assertFalse($signer->verify('message', base64_encode('too short'), $keyPair['public']));
    }

    public function testSignRejectsAMalformedSecretKey(): void
    {
        $signer = new PluginRegistrySigner();

        $this->expectException(\RuntimeException::class);

        $signer->sign('message', base64_encode('too short to be a secret key'));
    }

    public function testGenerateKeyPairProducesUsableBase64Keys(): void
    {
        $signer = new PluginRegistrySigner();

        $keyPair = $signer->generateKeyPair();

        self::assertSame(\SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, \strlen((string) base64_decode($keyPair['public'], true)));
        self::assertSame(\SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, \strlen((string) base64_decode($keyPair['secret'], true)));
    }
}
