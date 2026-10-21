<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends multipart transactional emails using the selected company's SMTP
 * account.
 */
final class SmtpSender
{
    /**
     * Sends HTML with a plain text alternative; transport failures propagate.
     */
    public function __invoke(array $config, array $payload, array $message): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['SMTP_SERVER'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['SMTP_USER'];
        $mail->Password = $config['SMTP_PASSWORD'];
        $mail->Port = (int) $config['SMTP_PORT'];
        $mail->SMTPSecure = $config['SMTP_ENCRYPTION'];
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($config['EMAIL_ADDRESS_FROM'], $payload['company']['name']);
        $mail->Sender = $config['EMAIL_ADDRESS_BOUNCE'];
        $mail->addAddress($payload['customer']['email']);
        $mail->isHTML(true);
        $mail->Subject = $message['subject'];
        $mail->Body = $message['html'];
        $mail->AltBody = $message['body'];
        $mail->send();
    }
}
