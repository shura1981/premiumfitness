<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;
use Throwable;

/**
 * Wrapper de PHPMailer configurable desde variables de entorno.
 *
 * Variables de entorno requeridas (validadas en bootstrap.php):
 *   MAIL_HOST        — servidor SMTP (ej. smtp.gmail.com)
 *   MAIL_PORT        — 587 (TLS) o 465 (SSL)
 *   MAIL_USERNAME    — cuenta SMTP
 *   MAIL_PASSWORD    — contraseña / app password
 *   MAIL_FROM        — dirección remitente (ej. no-reply@yourdomain.com)
 *   MAIL_FROM_NAME   — nombre remitente
 *   MAIL_ENCRYPTION  — tls | ssl
 *
 * Uso:
 *   $mailer = Mailer::fromEnv();
 *
 *   $mailer->send(
 *       MailMessage::to('user@example.com', 'John')
 *           ->subject('Welcome!')
 *           ->htmlTemplate('welcome', ['name' => 'John'])
 *   );
 */
class Mailer
{
    private const VIEWS_PATH = __DIR__ . '/../../../views/emails';

    private function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly string $encryption,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host:       $_ENV['MAIL_HOST'],
            port:       (int) $_ENV['MAIL_PORT'],
            username:   $_ENV['MAIL_USERNAME'],
            password:   $_ENV['MAIL_PASSWORD'],
            from:       $_ENV['MAIL_FROM'],
            fromName:   $_ENV['MAIL_FROM_NAME'],
            encryption: $_ENV['MAIL_ENCRYPTION'],
        );
    }

    /**
     * @throws RuntimeException si el envío falla
     */
    public function send(MailMessage $message): void
    {
        $mailer = $this->buildMailer();

        foreach ($message->getTo() as $r) {
            $mailer->addAddress($r['email'], $r['name']);
        }

        foreach ($message->getCc() as $r) {
            $mailer->addCC($r['email'], $r['name']);
        }

        foreach ($message->getBcc() as $r) {
            $mailer->addBCC($r['email'], $r['name']);
        }

        foreach ($message->getAttachments() as $path) {
            $mailer->addAttachment($path);
        }

        $mailer->Subject = $message->getSubject();

        $html = $message->getTemplateName() !== null
            ? $this->renderTemplate($message->getTemplateName(), array_merge(
                ['subject' => $message->getSubject(), 'year' => (string) date('Y')],
                $message->getTemplateVars(),
            ))
            : $message->getHtmlBody();

        $mailer->isHTML((bool) $html);
        $mailer->Body    = $html ?: $message->getPlainText();
        $mailer->AltBody = $message->getPlainText() ?: strip_tags($html);

        try {
            $mailer->send();
        } catch (Throwable $e) {
            throw new RuntimeException('Mailer error: ' . $mailer->ErrorInfo, previous: $e);
        }
    }

    private function buildMailer(): PHPMailer
    {
        $mailer = new PHPMailer(exceptions: true);

        $mailer->isSMTP();
        $mailer->Host       = $this->host;
        $mailer->Port       = $this->port;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $this->username;
        $mailer->Password   = $this->password;
        $mailer->SMTPSecure = $this->encryption === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mailer->CharSet  = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = PHPMailer::ENCODING_BASE64;

        $mailer->setFrom($this->from, $this->fromName);

        return $mailer;
    }

    /**
     * Renderiza views/emails/<name>.html reemplazando {{key}} con los valores.
     *
     * @param array<string, mixed> $vars
     */
    private function renderTemplate(string $name, array $vars): string
    {
        $file = self::VIEWS_PATH . "/{$name}.html";

        if (!is_file($file)) {
            throw new RuntimeException("Email template not found: {$name}.html");
        }

        $html = file_get_contents($file);

        if ($html === false) {
            throw new RuntimeException("Email template could not be read: {$name}.html");
        }

        foreach ($vars as $key => $value) {
            $html = str_replace('{{' . $key . '}}', (string) $value, $html);
        }

        return $html;
    }
}
