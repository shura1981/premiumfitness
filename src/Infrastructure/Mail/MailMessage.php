<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

/**
 * Value object que representa un mensaje de correo.
 *
 * Uso:
 *   $message = MailMessage::to('user@example.com', 'John')
 *       ->subject('Welcome!')
 *       ->htmlTemplate('welcome', ['name' => 'John']);
 *
 *   // O con HTML directo:
 *   $message = MailMessage::to('user@example.com')
 *       ->subject('Hello')
 *       ->html('<p>Hello world</p>');
 */
final class MailMessage
{
    /** @var list<array{email: string, name: string}> */
    private array $to      = [];
    /** @var list<array{email: string, name: string}> */
    private array $cc      = [];
    /** @var list<array{email: string, name: string}> */
    private array $bcc     = [];
    /** @var list<string> */
    private array $attachments = [];

    private string  $subject      = '(No subject)';
    private string  $htmlBody     = '';
    private string  $plainText    = '';
    private ?string $templateName = null;
    /** @var array<string, mixed> */
    private array   $templateVars = [];

    private function __construct() {}

    public static function to(string $email, string $name = ''): self
    {
        $msg     = new self();
        $msg->to = [['email' => $email, 'name' => $name]];

        return $msg;
    }

    public function addTo(string $email, string $name = ''): self
    {
        $this->to[] = ['email' => $email, 'name' => $name];

        return $this;
    }

    public function addCc(string $email, string $name = ''): self
    {
        $this->cc[] = ['email' => $email, 'name' => $name];

        return $this;
    }

    public function addBcc(string $email, string $name = ''): self
    {
        $this->bcc[] = ['email' => $email, 'name' => $name];

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function html(string $html): self
    {
        $this->htmlBody = $html;

        return $this;
    }

    public function text(string $plain): self
    {
        $this->plainText = $plain;

        return $this;
    }

    /**
     * Usar plantilla HTML en views/emails/<name>.php
     *
     * @param array<string, mixed> $vars Variables disponibles en la plantilla
     */
    public function htmlTemplate(string $templateName, array $vars = []): self
    {
        $this->templateName = $templateName;
        $this->templateVars = $vars;

        return $this;
    }

    public function attach(string $absolutePath): self
    {
        $this->attachments[] = $absolutePath;

        return $this;
    }

    // -----------------------------------------------------------------------
    // Getters (usados por Mailer)
    // -----------------------------------------------------------------------

    /** @return list<array{email: string, name: string}> */
    public function getTo(): array { return $this->to; }

    /** @return list<array{email: string, name: string}> */
    public function getCc(): array { return $this->cc; }

    /** @return list<array{email: string, name: string}> */
    public function getBcc(): array { return $this->bcc; }

    public function getSubject(): string { return $this->subject; }

    public function getHtmlBody(): string { return $this->htmlBody; }

    public function getPlainText(): string { return $this->plainText; }

    public function getTemplateName(): ?string { return $this->templateName; }

    /** @return array<string, mixed> */
    public function getTemplateVars(): array { return $this->templateVars; }

    /** @return list<string> */
    public function getAttachments(): array { return $this->attachments; }
}
