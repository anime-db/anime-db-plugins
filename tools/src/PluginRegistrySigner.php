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

namespace AnimeDb\Plugins\Tools;

/**
 * Ed25519 detached signing/verification of the exact published bytes of `plugins-registry.json`.
 *
 * The registry's signature is a cheap insurance against the maintainer's hosting mirror being
 * compromised, not a defense against a compromised CI — the signing key itself lives only as a
 * GitHub Actions secret consumed by this repo's own (protected) workflow. Signing operates on
 * raw bytes rather than re-decoding/re-encoding JSON, so it is immune to whitespace/key-order
 * differences a re-serialization step could introduce between "what was signed" and "what got
 * published".
 *
 * Keys are handled here as base64 strings (the natural shape for a GitHub Actions secret and for
 * a public key file committed to the repo), converted to/from the raw bytes libsodium expects.
 */
final class PluginRegistrySigner
{
    /**
     * @return array{public: string, secret: string} base64-encoded Ed25519 keypair
     */
    public function generateKeyPair(): array
    {
        $keyPair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        ];
    }

    /**
     * @return string base64-encoded 64-byte detached signature
     */
    public function sign(string $message, string $secretKeyBase64): string
    {
        $secretKey = base64_decode($secretKeyBase64, true);
        if ($secretKey === false || \strlen($secretKey) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Secret key must be a base64-encoded Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }

    public function verify(string $message, string $signatureBase64, string $publicKeyBase64): bool
    {
        $signature = base64_decode($signatureBase64, true);
        $publicKey = base64_decode($publicKeyBase64, true);

        if (
            $signature === false
            || $publicKey === false
            || \strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES
            || \strlen($publicKey) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
    }
}
