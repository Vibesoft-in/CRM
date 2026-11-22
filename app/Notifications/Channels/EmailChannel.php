<?php
// app/Notifications/Channels/EmailChannel.php
namespace App\Notifications\Channels;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

class EmailChannel implements NotificationChannelInterface {
    public function getName(): string {
        return 'email';
    }

    public function send(array $notification, array $user, array $config = []): array {
        $toEmail = trim($user['email'] ?? '');
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'response' => null,
                'error' => "User does not have a valid email address ('{$toEmail}')"
            ];
        }

        $smtpHost = $config['smtp_host'] ?? '';
        $smtpPort = (int)($config['smtp_port'] ?? 587);
        $smtpUser = $config['smtp_user'] ?? '';
        $smtpPass = $config['smtp_pass'] ?? '';
        $smtpSecure = $config['smtp_secure'] ?? 'tls';
        $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($smtpUser ?: 'no-reply@vibesoft.in');
        $fromName = !empty($config['smtp_from_name']) ? $config['smtp_from_name'] : 'VibeSoft CMS';

        $phpmailerPath = __DIR__ . '/../../../lib/phpmailer/PHPMailer.php';
        if (file_exists($phpmailerPath)) {
            require_once __DIR__ . '/../../../lib/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/../../../lib/phpmailer/SMTP.php';
            require_once __DIR__ . '/../../../lib/phpmailer/Exception.php';

            if (empty($smtpHost)) {
                return [
                    'success' => false,
                    'response' => null,
                    'error' => 'SMTP Host is not configured in Notification Settings'
                ];
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->SMTPAuth = !empty($smtpUser);
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                if ($smtpSecure === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($smtpSecure === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPAutoTLS = false;
                }
                $mail->Port = $smtpPort;
                $mail->CharSet = 'UTF-8';
                $mail->Timeout = 10;

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail, $user['name'] ?? 'User');

                $subject = $notification['title'] ?? 'Notification from ' . $fromName;
                $mail->Subject = $subject;

                // Generate HTML body
                $htmlBody = $this->buildHtmlTemplate($notification, $user, $fromName);
                $mail->isHTML(true);
                $mail->Body = $htmlBody;
                $mail->AltBody = strip_tags($notification['message'] ?? '');

                $mail->send();

                return [
                    'success' => true,
                    'response' => "Email sent successfully via SMTP to {$toEmail}",
                    'error' => null
                ];
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                return [
                    'success' => false,
                    'response' => null,
                    'error' => 'PHPMailer error: ' . $e->getMessage()
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'response' => null,
                    'error' => 'Email dispatch error: ' . $e->getMessage()
                ];
            }
        } else {
            // Fallback to PHP native mail()
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: ' . $fromName . ' <' . $fromEmail . '>',
                'Reply-To: ' . $fromEmail,
                'X-Mailer: PHP/' . phpversion()
            ];
            $htmlBody = $this->buildHtmlTemplate($notification, $user, $fromName);
            $subject = $notification['title'] ?? 'Notification from ' . $fromName;
            $sent = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
            if ($sent) {
                return [
                    'success' => true,
                    'response' => "Email sent successfully via native mail() to {$toEmail}",
                    'error' => null
                ];
            } else {
                return [
                    'success' => false,
                    'response' => null,
                    'error' => 'PHPMailer not installed in lib/phpmailer/ and native mail() failed or is disabled on this server'
                ];
            }
        }
    }

    private function buildHtmlTemplate(array $notification, array $user, string $companyName): string {
        $title = htmlspecialchars($notification['title'] ?? 'Notification');
        $message = nl2br(htmlspecialchars($notification['message'] ?? ''));
        $userName = htmlspecialchars($user['name'] ?? 'Team Member');
        $link = $notification['link'] ?? '';
        $year = date('Y');

        $actionBtn = '';
        if (!empty($link)) {
            $safeLink = htmlspecialchars($link);
            $actionBtn = "
                <div style='margin-top: 25px; text-align: center;'>
                    <a href='{$safeLink}' style='background-color: #0d6efd; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block; font-size: 14px;'>Open in CRM</a>
                </div>
            ";
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>{$title}</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 24px;'>
            <table cellpadding='0' cellspacing='0' border='0' width='100%' style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                <tr>
                    <td style='background-color: #0d6efd; padding: 20px 30px; text-align: left;'>
                        <span style='color: #ffffff; font-size: 18px; font-weight: bold; letter-spacing: 0.5px;'>{$companyName}</span>
                    </td>
                </tr>
                <tr>
                    <td style='padding: 30px;'>
                        <h2 style='color: #2b3674; font-size: 20px; margin-top: 0; margin-bottom: 15px;'>{$title}</h2>
                        <p style='color: #4b5563; font-size: 15px; line-height: 1.5; margin-bottom: 20px;'>Hello <strong>{$userName}</strong>,</p>
                        <div style='background-color: #f8fafc; border-left: 4px solid #0d6efd; padding: 15px 20px; margin-bottom: 20px; color: #334155; font-size: 14px; line-height: 1.6;'>
                            {$message}
                        </div>
                        {$actionBtn}
                        <p style='color: #94a3b8; font-size: 12px; margin-top: 35px; margin-bottom: 0; border-top: 1px solid #e2e8f0; padding-top: 15px; text-align: center;'>
                            This is an automated notification from {$companyName}. Please do not reply directly to this email.<br>
                            &copy; {$year} {$companyName}. All rights reserved.
                        </p>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }
}
