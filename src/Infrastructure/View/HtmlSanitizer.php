<?php

declare(strict_types=1);

namespace App\Infrastructure\View;

/**
 * Prepara texto largo administrado desde el panel (descripción, modo de uso,
 * registro sanitario) para imprimirlo con `|raw` en Twig.
 *
 * El HTML del panel es de confianza —igual que en el frontend Angular, que lo
 * renderiza con innerHTML— pero se eliminan script/style/iframe, manejadores
 * inline y URLs `javascript:` como defensa en profundidad. El texto plano se
 * escapa y se divide en párrafos.
 */
final readonly class HtmlSanitizer
{
    private const array DANGEROUS_PATTERNS = [
        '#<(script|style|iframe)\b[^>]*>.*?</\1>#is',
        '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
        '#\s(?:href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\')#i',
    ];

    public static function richText(?string $value): string
    {
        $value = trim($value ?? '');

        if ($value === '') {
            return '';
        }

        return str_contains($value, '<')
            ? self::sanitizeHtml($value)
            : self::paragraphs($value);
    }

    /** Texto sin marcado: se escapa y cada bloque separado por línea en blanco es un <p>. */
    private static function paragraphs(string $value): string
    {
        $parts = array_filter(preg_split('/\R{2,}/', $value) ?: []);

        return implode('', array_map(
            static fn (string $part): string => '<p>' . nl2br(self::escape($part)) . '</p>',
            $parts
        ));
    }

    private static function sanitizeHtml(string $value): string
    {
        $clean = preg_replace(self::DANGEROUS_PATTERNS, '', $value);

        return is_string($clean) ? trim($clean) : '';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
