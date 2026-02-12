<?php
declare(strict_types=1);

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function otp_ensure_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_login_otp (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            verified_at DATETIME NULL,
            consumed_at DATETIME NULL,
            ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_otp_user (user_id),
            INDEX idx_otp_email (email),
            INDEX idx_otp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function otp_generate_code(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function otp_send_email(string $email, string $name, string $otpCode): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email, $name ?: $email);
        $mail->isHTML(true);
        $mail->Subject = 'TNVS Admin Login OTP';
        $mail->Body = '
            <div style="font-family:Arial,sans-serif;line-height:1.5">
              <h2 style="margin:0 0 8px">Admin Login Verification</h2>
              <p style="margin:0 0 10px">Use this one-time code to continue login:</p>
              <div style="font-size:28px;font-weight:700;letter-spacing:4px;margin:6px 0 12px;">' . htmlspecialchars($otpCode, ENT_QUOTES) . '</div>
              <p style="margin:0;color:#555">This code expires in 10 minutes.</p>
            </div>';
        $mail->AltBody = "Your TNVS admin login OTP is: {$otpCode}. It expires in 10 minutes.";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

function otp_create(PDO $pdo, int $userId, string $email, string $ip): array {
    otp_ensure_table($pdo);
    $pdo->prepare("UPDATE auth_login_otp SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL AND verified_at IS NULL")
        ->execute([$userId]);

    $code = otp_generate_code();
    $ins = $pdo->prepare("
        INSERT INTO auth_login_otp (user_id, email, otp_code, expires_at, ip)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)
    ");
    $ins->execute([$userId, $email, $code, $ip]);
    $otpId = (int)$pdo->lastInsertId();
    return ['id' => $otpId, 'code' => $code];
}

function otp_verify(PDO $pdo, int $otpId, int $userId, string $code): array {
    otp_ensure_table($pdo);
    $st = $pdo->prepare("
        SELECT id, otp_code, expires_at, attempts, verified_at, consumed_at
        FROM auth_login_otp
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $st->execute([$otpId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 'OTP session not found. Please log in again.'];

    if (!empty($row['consumed_at'])) return [false, 'OTP already used. Please log in again.'];
    if (!empty($row['verified_at'])) return [false, 'OTP already verified. Please log in again.'];
    if (strtotime((string)$row['expires_at']) < time()) return [false, 'OTP expired. Please log in again.'];
    if ((int)$row['attempts'] >= 5) return [false, 'Too many OTP attempts. Please log in again.'];

    if (trim($code) !== (string)$row['otp_code']) {
        $pdo->prepare("UPDATE auth_login_otp SET attempts = attempts + 1 WHERE id = ?")->execute([$otpId]);
        return [false, 'Invalid OTP code.'];
    }

    $pdo->prepare("UPDATE auth_login_otp SET verified_at = NOW(), consumed_at = NOW() WHERE id = ?")->execute([$otpId]);
    return [true, 'OTP verified.'];
}

