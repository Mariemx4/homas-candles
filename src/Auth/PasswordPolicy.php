<?php

declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;
use RuntimeException;

/** One byte-length and hashing policy for signup, reset and both login realms. */
final class PasswordPolicy
{
    public const MIN_CHARACTERS = 8;
    public const MAX_BYTES = 4096;
    public const BCRYPT_MAX_BYTES = 72;
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$SFlxcUs0OUdpMnNkN3J4ZQ$lvfWaXdWQJzEWf1IlyF72wqB55GXhdoIKSA/lkcgnwg';
    private const OPTIONS = ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];

    public static function newPasswordError(string $password): ?string
    {
        $bytes = strlen($password);
        if ($bytes === 0) {
            return 'Password is required.';
        }
        if ($bytes > self::MAX_BYTES) {
            return 'Password must be 4,096 bytes or fewer.';
        }
        $characters = preg_match_all('/./us', $password);
        if ($characters === false) {
            return 'Password contains invalid text.';
        }
        if ($characters < self::MIN_CHARACTERS || preg_match('/[A-Za-z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1) {
            return 'Use at least 8 characters, including a letter and a number.';
        }
        return null;
    }

    public static function hashNew(string $password): string
    {
        if (self::newPasswordError($password) !== null) {
            throw new InvalidArgumentException('Password does not meet the application policy.');
        }
        return self::hashVerified($password);
    }

    /** Rehash an already verified legacy password without imposing new signup rules. */
    public static function hashVerified(string $password): string
    {
        if ($password === '' || strlen($password) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Verified password length is invalid.');
        }
        if (!defined('PASSWORD_ARGON2ID') || !in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            throw new RuntimeException('Argon2id password hashing is unavailable.');
        }
        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public static function verify(string $password, string $storedHash): bool
    {
        $bytes = strlen($password);
        if ($bytes === 0 || $bytes > self::MAX_BYTES
            || (password_get_info($storedHash)['algoName'] === 'bcrypt' && $bytes >= self::BCRYPT_MAX_BYTES)) {
            // Keep a comparable expensive operation on rejected length paths.
            password_verify('', self::DUMMY_HASH);
            return false;
        }
        return password_verify($password, $storedHash);
    }

    public static function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}
