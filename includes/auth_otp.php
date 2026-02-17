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
        $safeCode = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name !== '' ? $name : 'Admin', ENT_QUOTES, 'UTF-8');
        $mail->Body = '
<!doctype html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5f2ff;font-family:Segoe UI,Arial,sans-serif;color:#241a47;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f2ff;padding:24px 10px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #e8ddff;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="padding:18px 24px;background:linear-gradient(120deg,#6d39df,#5230c4);color:#ffffff;">
              <div style="font-size:20px;font-weight:700;letter-spacing:.2px;">TNVS Admin Verification</div>
              <div style="font-size:13px;opacity:.9;margin-top:4px;">Secure one-time login code</div>
            </td>
          </tr>
          <tr>
            <td style="padding:22px 24px;">
              <p style="margin:0 0 10px;font-size:15px;">Hi ' . $safeName . ',</p>
              <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Use this one-time password (OTP) to complete your admin sign-in:</p>
              <div style="margin:0 0 14px;padding:14px 16px;border:1px dashed #bfa8f6;background:#f8f4ff;border-radius:12px;text-align:center;">
                <span style="font-size:34px;font-weight:800;letter-spacing:8px;color:#3f2a96;">' . $safeCode . '</span>
              </div>
              <p style="margin:0 0 10px;font-size:14px;color:#4f4574;">This code expires in <strong>3 minutes</strong>.</p>
              <p style="margin:0;font-size:13px;color:#6f6694;">If you did not request this login, you can safely ignore this email.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:12px 24px;background:#faf8ff;border-top:1px solid #eee5ff;font-size:12px;color:#7a71a1;">
              TNVS Logistics 1 • ViaHale
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
        $mail->AltBody = "TNVS Admin Verification\n\nYour OTP code is: {$otpCode}\nThis code expires in 3 minutes.\nIf you did not request this login, ignore this email.";

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
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 MINUTE), ?)
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
