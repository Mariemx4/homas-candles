<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;
use RuntimeException;

/** Persistent, per-account and per-IP login backoff shared by both login services. */
final class LoginThrottle
{
    private const WINDOW_SECONDS = 900;
    private const MAX_DELAY_SECONDS = 900;

    public function __construct(private readonly PDO $db, private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('Login throttle key is not configured.');
        }
    }

    public function accountBucket(?int $accountId, string $identifier): string
    {
        $value = $accountId === null ? 'unknown:' . substr(strtolower(trim($identifier)), 0, 512) : 'id:' . $accountId;
        return hash_hmac('sha256', $value, $this->key);
    }

    public function ipBucket(?string $remoteAddress): string
    {
        // REMOTE_ADDR is supplied by the web server. Never trust client-sent forwarding headers here.
        $ip = is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP) !== false
            ? $remoteAddress : 'unknown';
        return hash_hmac('sha256', $ip, $this->key);
    }

    public function isBlocked(string $realm, string $accountBucket, string $ipBucket): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM login_throttle WHERE realm = :realm AND
             ((scope = :account_scope AND bucket_hash = :account_hash) OR (scope = :ip_scope AND bucket_hash = :ip_hash))
             AND blocked_until > NOW(6)'
        );
        $statement->execute([
            'realm' => $realm, 'account_scope' => 'ACCOUNT', 'account_hash' => $accountBucket,
            'ip_scope' => 'IP', 'ip_hash' => $ipBucket,
        ]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function failed(string $realm, string $accountBucket, string $ipBucket): void
    {
        $this->db->beginTransaction();
        try {
            // Fixed lock order prevents deadlocks when several requests fail together.
            $this->increase($realm, 'ACCOUNT', $accountBucket, 5);
            $this->increase($realm, 'IP', $ipBucket, $realm === 'ADMIN' ? 10 : 20);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        // Log pseudonyms only; never log identifiers, raw IPs, passwords or supplied text.
        error_log(sprintf('login_failed realm=%s account=%s ip=%s', $realm, substr($accountBucket, 0, 12), substr($ipBucket, 0, 12)));
        if (random_int(1, 1000) === 1) {
            $this->db->exec('DELETE FROM login_throttle WHERE last_failure_at < NOW(6) - INTERVAL 30 DAY');
        }
    }

    public function blockedAttempt(string $realm, string $accountBucket, string $ipBucket): void
    {
        error_log(sprintf('login_blocked realm=%s account=%s ip=%s', $realm, substr($accountBucket, 0, 12), substr($ipBucket, 0, 12)));
    }

    public function succeeded(string $realm, string $accountBucket, string $ipBucket): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM login_throttle WHERE realm = :realm AND scope = :scope AND bucket_hash = :bucket_hash'
        );
        $statement->execute(['realm' => $realm, 'scope' => 'ACCOUNT', 'bucket_hash' => $accountBucket]);
        error_log(sprintf('login_succeeded realm=%s account=%s ip=%s', $realm, substr($accountBucket, 0, 12), substr($ipBucket, 0, 12)));
    }

    private function increase(string $realm, string $scope, string $bucketHash, int $threshold): void
    {
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO login_throttle (realm, scope, bucket_hash, failures, last_failure_at)
             VALUES (:realm, :scope, :bucket_hash, 0, NOW(6))'
        );
        $insert->execute(['realm' => $realm, 'scope' => $scope, 'bucket_hash' => $bucketHash]);
        $read = $this->db->prepare(
            'SELECT failures, TIMESTAMPDIFF(SECOND, last_failure_at, NOW(6)) AS age_seconds
             FROM login_throttle WHERE realm = :realm AND scope = :scope AND bucket_hash = :bucket_hash FOR UPDATE'
        );
        $read->execute(['realm' => $realm, 'scope' => $scope, 'bucket_hash' => $bucketHash]);
        $current = $read->fetch();
        $failures = (int) $current['age_seconds'] >= self::WINDOW_SECONDS ? 1 : min(1000, (int) $current['failures'] + 1);
        $delay = $failures < $threshold ? 0 : min(self::MAX_DELAY_SECONDS, 2 ** min(10, $failures - $threshold + 1));
        $update = $this->db->prepare(
            'UPDATE login_throttle SET failures = :failures, last_failure_at = NOW(6),
             blocked_until = DATE_ADD(NOW(6), INTERVAL :delay SECOND)
             WHERE realm = :realm AND scope = :scope AND bucket_hash = :bucket_hash'
        );
        $update->execute([
            'failures' => $failures, 'delay' => $delay,
            'realm' => $realm, 'scope' => $scope, 'bucket_hash' => $bucketHash,
        ]);
    }
}
