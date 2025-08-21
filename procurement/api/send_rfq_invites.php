
declare(strict_types=1);
require_once __DIR__.'/../../includes/config.php';
require_once __DIR__.'/../../includes/auth.php';
require_login();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__.'/../../vendor/autoload.php';

function genToken(): string { return bin2hex(random_bytes(32)); } // 64 hex

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('POST required');
  $rfq_id = (int)($_POST['rfq_id'] ?? 0);
  if ($rfq_id <= 0) throw new Exception('rfq_id required');

  // fetch recipients for this RFQ (join to suppliers to get email/name)
  $st = $pdo->prepare("SELECT rr.id, rr.supplier_id, s.name AS supplier_name, s.email, rr.invite_token
                       FROM rfq_recipients rr
                       JOIN suppliers s ON s.id = rr.supplier_id
                       WHERE rr.rfq_id = ?");
  $st->execute([$rfq_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $pdo->beginTransaction();
  $exp = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

  foreach ($rows as $r) {
    $token = $r['invite_token'] ?: genToken();
    if (!$r['invite_token']) {
      $up = $pdo->prepare("UPDATE rfq_recipients
                           SET invite_token=?, token_expires_at=?, sent_at=NOW()
                           WHERE rfq_id=? AND supplier_id=?");
      $up->execute([$token, $exp, $rfq_id, (int)$r['supplier_id']]);
    } else {
      $up = $pdo->prepare("UPDATE rfq_recipients SET sent_at=NOW()
                           WHERE rfq_id=? AND supplier_id=?");
      $up->execute([$rfq_id, (int)$r['supplier_id']]);
    }

    $inviteUrl = BASE_URL."/rfq/quote.php?token=".$token;

    // send email
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;

    $mail->setFrom('procurement@yourdomain.tld', 'ViaHale Procurement');
    $mail->addAddress($r['email'], $r['supplier_name']);
    $mail->Subject = "RFQ #$rfq_id – Invitation to Quote";
    $mail->isHTML(true);
    $mail->Body = "
      <p>Hi {$r['supplier_name']},</p>
      <p>You are invited to submit a quotation for RFQ #$rfq_id.</p>
      <p><a href='$inviteUrl'>Submit your quote</a> (link expires on $exp)</p>
      <p>Thank you,<br>ViaHale Procurement</p>
      <img src='".BASE_URL."/rfq/open.gif?token=$token' width='1' height='1' alt='' />
    ";
    $mail->AltBody = "Open this link to submit your quote: $inviteUrl (expires $exp)";
    $mail->send();
  }

  $pdo->commit();
  echo json_encode(['ok' => true]);
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error' => $e->getMessage()]);
}
