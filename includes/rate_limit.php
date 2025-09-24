<?php
declare(strict_types=1);

function login_blocked(PDO $pdo, string $identifier, string $ip, int $maxAttempts = 5, int $windowSec = 900): array {
    $sql = "
        SELECT
            SUM(CASE WHEN identifier = :ident THEN 1 ELSE 0 END) AS ident_fails,
            SUM(CASE WHEN ip = :ip THEN 1 ELSE 0 END)            AS ip_fails,
            GREATEST(
                0,
                CEIL( (:w1 - TIMESTAMPDIFF(SECOND, MAX(attempted_at), UTC_TIMESTAMP())) / 60 )
            ) AS mins_left
        FROM auth_login_attempts
        WHERE success = 0
          AND attempted_at >= TIMESTAMPADD(SECOND, -:w2, UTC_TIMESTAMP())
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':ident' => $identifier,
        ':ip'    => $ip,
        ':w1'    => $windowSec,
        ':w2'    => $windowSec,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['ident_fails'=>0,'ip_fails'=>0,'mins_left'=>0];

    $fails = max((int)$row['ident_fails'], (int)$row['ip_fails']);
    $mins  = (int)$row['mins_left'];

    return [$fails >= $maxAttempts, $mins, $fails];
}

function record_login_attempt(PDO $pdo, string $identifier, string $ip, bool $success): void {
    $st = $pdo->prepare("
        INSERT INTO auth_login_attempts (identifier, ip, attempted_at, success)
        VALUES (:ident, :ip, UTC_TIMESTAMP(), :success)
    ");
    $st->execute([
        ':ident'   => $identifier,
        ':ip'      => $ip,
        ':success' => $success ? 1 : 0
    ]);
    $pdo->exec("DELETE FROM auth_login_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)");
}

function clear_attempts(PDO $pdo, string $identifier, string $ip): void {
    $st = $pdo->prepare("DELETE FROM auth_login_attempts WHERE identifier = :ident OR ip = :ip");
    $st->execute([':ident'=>$identifier, ':ip'=>$ip]);
}
