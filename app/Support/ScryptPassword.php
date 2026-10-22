<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ScryptPassword
{
    private const OPS_LIMIT = 16384;

    private const MEM_LIMIT = 8;

    private const PARALLELISM = 1;

    private const HASH_LENGTH = 64;

    /**
     * Verify a password against a Node-style scrypt hash (`scrypt$saltB64url$hashB64url`).
     */
    public static function verify(string $password, string $stored): bool
    {
        $parts = explode('$', $stored);
        if (count($parts) !== 3 || $parts[0] !== 'scrypt') {
            return false;
        }

        [, $saltB64, $hashB64] = $parts;

        $salt = self::b64urlDecode($saltB64);
        $expected = self::b64urlDecode($hashB64);

        if ($salt === false || $expected === false) {
            return false;
        }

        if (function_exists('sodium_crypto_pwhash_scryptsalsa208sha256_ll')) {
            $length = max(strlen($expected), self::HASH_LENGTH);
            $hash = sodium_crypto_pwhash_scryptsalsa208sha256_ll(
                $password,
                $salt,
                self::OPS_LIMIT,
                self::MEM_LIMIT,
                self::PARALLELISM,
                $length,
            );

            return hash_equals($expected, $hash);
        }

        return self::verifyViaNode($password, $stored);
    }

    private static function verifyViaNode(string $password, string $stored): bool
    {
        $root = dirname(__DIR__, 2);
        $script = $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'verify-scrypt.cjs';
        if (! is_file($script)) {
            return false;
        }

        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            return false;
        }

        $process = new Process([$node, $script, $password, $stored], $root);
        $process->run();

        return $process->isSuccessful();
    }

    private static function b64urlDecode(string $value): string|false
    {
        $padded = strtr($value, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? false : $decoded;
    }
}
