<?php
require_once __DIR__ . '/mail_config.php';

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function buildEmailTemplate(string $title, string $intro, string $bodyHtml, ?string $ctaText = null, ?string $ctaUrl = null): string
{
    $brandColor = '#6532C9';
    $accentColor = '#7C3BFF';
    $baseUrl = rtrim(BASE_URL, '/');
    $logoUrl = $baseUrl . '/img/logo.png';

    $ctaBlock = '';
    if ($ctaText && $ctaUrl) {
        $ctaBlock = '
            <tr>
              <td align="center" style="padding: 24px 24px 0 24px;">
                <a href="'.htmlspecialchars($ctaUrl).'"
                   style="display:inline-block;padding:12px 28px;border-radius:999px;
                          background:linear-gradient(135deg,'.$brandColor.','.$accentColor.');
                          color:#ffffff;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;
                          font-size:14px;font-weight:600;text-decoration:none;">
                  '.htmlspecialchars($ctaText).'
                </a>
              </td>
            </tr>
        ';
    }

    return '
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>'.htmlspecialchars($title).'</title>
</head>
<body style="margin:0;padding:0;background:#f4f3fb;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f4f3fb;padding:24px 0;">
    <tr>
      <td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(38,19,89,0.08);">
          <tr>
            <td style="padding:20px 24px 8px 24px;background:'.$brandColor.';background:linear-gradient(135deg,'.$brandColor.','.$accentColor.');color:#ffffff;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="left" style="font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;font-size:14px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;opacity:.9;">
                    TNVS Vendor Portal
                  </td>
                  <td align="right">
                    <img src="'.htmlspecialchars($logoUrl).'" alt="TNVS" style="height:36px;display:block;border:0;"/>
                  </td>
                </tr>
                <tr>
                  <td colspan="2" style="padding-top:10px;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;font-size:20px;font-weight:700;">
                    '.htmlspecialchars($title).'
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:24px;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;font-size:14px;line-height:1.6;color:#312b45;">
              '.$intro.'
            </td>
          </tr>

          '.$ctaBlock.'

          <tr>
            <td style="padding:16px 24px 24px 24px;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;font-size:13px;line-height:1.6;color:#4b4762;">
              '.$bodyHtml.'
            </td>
          </tr>

          <tr>
            <td style="padding:16px 24px 24px 24px;border-top:1px solid #ece8ff;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Inter\',sans-serif;font-size:11px;color:#8b88a3;">
              This is an automated message from the TNVS Vendor Portal. Please do not reply directly to this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function sendVendorPendingEmail(array $vendor): bool
{
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
        $mail->addAddress($vendor['email'], $vendor['contact_person'] ?? '');

        $name    = $vendor['contact_person'] ?: ($vendor['company_name'] ?? 'Vendor');
        $company = $vendor['company_name'] ?? '';

        $title = 'Vendor Registration Received';
        $intro = 'Dear '.$name.',<br><br>'
               . 'Thank you for registering <strong>'.htmlspecialchars($company).'</strong> as a vendor with TNVS.';

        $bodyHtml = '
          Your registration has been successfully received and is now <strong>pending review</strong> by our Vendor Management team.<br><br>
          Once your documents are checked and your company is validated, we will send another email to confirm whether your application has been <strong>approved</strong> or <strong>rejected</strong>.<br><br>
          <strong>No further action is required from you at this time.</strong><br><br>
          Regards,<br>
          TNVS Procurement Team
        ';

        $html = buildEmailTemplate($title, $intro, $bodyHtml);

        $mail->isHTML(true);
        $mail->Subject = 'TNVS Vendor Registration Received';
        $mail->Body    = $html;
        $mail->AltBody = "Dear {$name},\n\n"
            ."Thank you for registering {$company} as a vendor with TNVS.\n\n"
            ."Your registration has been received and is pending review. We will notify you once it is approved or rejected.\n\n"
            ."Regards,\nTNVS Procurement Team";

        return $mail->send();

    } catch (Exception $e) {
        error_log('MAIL ERROR (pending): '.$e->getMessage());
        return false;
    }
}

function sendVendorStatusEmail(array $vendor, string $status): bool
{
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
        $mail->addAddress($vendor['email'], $vendor['contact_person'] ?? '');

        $name    = $vendor['contact_person'] ?: ($vendor['company_name'] ?? 'Vendor');
        $company = $vendor['company_name'] ?? '';
        $login   = rtrim(BASE_URL, '/') . '/login.php';

        if ($status === 'approved') {
            $title = 'Vendor Application Approved';
            $intro = 'Dear '.$name.',<br><br>'
                   . 'Great news! Your vendor application for <strong>'.htmlspecialchars($company).'</strong> has been <strong>APPROVED</strong>.';

            $bodyHtml = '
              You can now access the <strong>TNVS Vendor Portal</strong> to participate in sourcing events, submit quotations, and track awards in real time.<br><br>
              <strong>Login details</strong><br>
              Email: <code>'.htmlspecialchars($vendor['email']).'</code><br>
              Password: the password you created during registration.<br><br>
              If you forget your password, you may use the <em>Forgot Password</em> link on the login page.<br><br>
              Regards,<br>
              TNVS Procurement Team
            ';

            $html = buildEmailTemplate($title, $intro, $bodyHtml, 'Open Vendor Portal', $login);

            $mail->Subject = 'TNVS Vendor Application Approved';
            $mail->Body    = $html;
            $mail->AltBody = "Dear {$name},\n\n"
                ."Your vendor application for {$company} has been APPROVED.\n\n"
                ."You can now log in to the TNVS Vendor Portal:\n{$login}\n\n"
                ."Username: {$vendor['email']}\n"
                ."Regards,\nTNVS Procurement Team";

        } elseif ($status === 'rejected') {
            $title = 'Vendor Application Result';
            $intro = 'Dear '.$name.',<br><br>'
                   . 'We regret to inform you that your vendor application for <strong>'.htmlspecialchars($company).'</strong> has been <strong>REJECTED</strong>.';

            $reason = trim($vendor['review_note'] ?? '');
            $reasonHtml = $reason !== ''
                ? '<strong>Reason provided:</strong><br>'.nl2br(htmlspecialchars($reason)).'<br><br>'
                : '';

            $bodyHtml = '
              '.$reasonHtml.'
              You may review and update your submitted documents or business information, then submit a new application if applicable.<br><br>
              Regards,<br>
              TNVS Procurement Team
            ';

            $html = buildEmailTemplate($title, $intro, $bodyHtml);

            $mail->Subject = 'TNVS Vendor Application Result';
            $mail->Body    = $html;
            $mail->AltBody = "Dear {$name},\n\n"
                ."Your vendor application for {$company} has been REJECTED.\n\n"
                .($reason !== '' ? "Reason:\n{$reason}\n\n" : '')
                ."You may correct your information and reapply.\n\n"
                ."Regards,\nTNVS Procurement Team";

        } else {
            return false;
        }

        $mail->isHTML(true);
        return $mail->send();

    } catch (Exception $e) {
        error_log('MAIL ERROR (status): '.$e->getMessage());
        return false;
    }
}
