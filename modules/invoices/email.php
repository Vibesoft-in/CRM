<?php
require_once '../../includes/functions.php';
require_once '../../includes/layout.php';
require_login();
require_once __DIR__ . '/../../config.php';
$id = intval($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT i.*, c.* FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = ?");
$stmt->execute([$id]); $invoice = $stmt->fetch();
if (!$invoice) { header('Location:list.php'); exit; }

$filepath = INVOICE_PDF_DIR . "invoice_{$invoice['invoice_no']}.pdf";
if (!file_exists($filepath)) {
    // try generating PDF on the fly
    header('Location: download.php?id=' . $id); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to']);
    $subject = trim($_POST['subject']);
    $body = trim($_POST['body']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $err = 'Invalid email';
    else {
        // PHPMailer usage
        $phpmailer_path = __DIR__ . '/../../lib/phpmailer/PHPMailer.php';
        if (!file_exists($phpmailer_path)) $err = 'Mailer not installed';
        else {
            require_once __DIR__ . '/../../lib/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/../../lib/phpmailer/SMTP.php';
            require_once __DIR__ . '/../../lib/phpmailer/Exception.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                // SMTP config - replace with your SMTP
                $mail->isSMTP();
                $mail->Host = 'smtp.example.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'you@example.com';
                $mail->Password = 'yourpassword';
                $mail->SMTPSecure = 'tls';
                $mail->Port = 587;

                $mail->setFrom('you@example.com', 'Your Company');
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->addAttachment($filepath);

                $mail->send();
                header('Location: view.php?id=' . $id);
                exit;
            } catch (Exception $e) {
                $err = "Mail error: " . $e->getMessage();
            }
        }
    }
}

?>
<div class="container my-4">
  <div class="card p-3">
    <h4>Email Invoice</h4>
    <?php if ($err) echo "<div class='alert alert-danger'>".e($err)."</div>"; ?>
    <form method="post">
      <div class="mb-2"><label>To</label><input name="to" class="form-control" value="<?= e($invoice['email'] ?: $invoice['billing_address']) ?>"></div>
      <div class="mb-2"><label>Subject</label><input name="subject" class="form-control" value="Invoice <?= e($invoice['invoice_no']) ?>"></div>
      <div class="mb-2"><label>Message</label><textarea name="body" class="form-control">Please find attached invoice <?= e($invoice['invoice_no']) ?>.</textarea></div>
      <button class="btn btn-primary">Send</button>
    </form>
  </div>
</div>
<?php include '../../includes/footer.php'; ?>
