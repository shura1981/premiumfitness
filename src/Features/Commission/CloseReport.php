<?php

declare(strict_types=1);

namespace App\Features\Commission;

use App\Infrastructure\Database\DB;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Mail\MailMessage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PDO;
use RuntimeException;

/**
 * Reporte del cierre mensual (spec negocio/05): el Excel que financiero usa para pagar y el correo
 * que lo anuncia. El Excel vive en `storage/private/reports`, fuera de la carpeta pública: solo se
 * descarga desde el admin con sesión. Regenerarlo no cambia ningún importe del cierre.
 */
class CloseReport
{
    private readonly PDO $pdo;
    private readonly string $directory;

    public function __construct()
    {
        $this->pdo = DB::getInstance();
        $path      = (string) ($_ENV['REPORTS_STORAGE_PATH'] ?? 'storage/private/reports');
        $this->directory = rtrim(str_starts_with($path, '/') ? $path : __DIR__ . '/../../../' . $path, '/');
    }

    /** Ruta absoluta del Excel de un período, o null si aún no se generó. */
    public function pathOf(int $periodId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT file_name FROM commission_periods WHERE id = ?');
        $stmt->execute([$periodId]);
        $name = $stmt->fetchColumn();

        if (!is_string($name) || $name === '') {
            return null;
        }

        $file = $this->directory . '/' . basename($name);

        return is_file($file) ? $file : null;
    }

    /** Genera (o regenera) el Excel del período y guarda su nombre. Devuelve la ruta. */
    public function generate(int $periodId): string
    {
        $period = $this->period($periodId);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo crear la carpeta de reportes.');
        }

        // El nombre no se puede adivinar; aun así, la carpeta no se sirve por web.
        $name = sprintf('cierre-comision-%s-%s.xlsx', $period['period'], bin2hex(random_bytes(6)));
        $file = $this->directory . '/' . $name;
        $from = $period['period_start'] . ' 00:00:00';
        $to   = $period['period_end'] . ' 23:59:59';

        $writer = new Writer();
        $writer->openToFile($file);

        $this->sheet($writer, 'Resumen', ['Concepto', 'Valor'], [
            ['Período', CommissionCalendar::label($period['period'])],
            ['Cerrado el', $period['closed_at']],
            ['Entrenadores con liquidación', (int) $period['trainers_count']],
            ['Pedidos con comisión', (int) $period['orders_count']],
            ['Total a pagar', (float) $period['total_amount']],
            ['Comisión generada', $this->scalar('SELECT COALESCE(SUM(commission_amount), 0) FROM commission_settlements WHERE period_id = ?', [$periodId])],
            ['Convertida a puntos', $this->scalar('SELECT COALESCE(SUM(conversions_amount), 0) FROM commission_settlements WHERE period_id = ?', [$periodId])],
            ['Ajustes', $this->scalar('SELECT COALESCE(SUM(adjustments_amount), 0) FROM commission_settlements WHERE period_id = ?', [$periodId])],
        ], first: true);

        $this->sheet($writer, 'Liquidaciones', ['Documento', 'Entrenador', 'Celular', 'Correo', 'Pedidos', 'Comisión', 'Convertido a puntos', 'Ajustes', 'Total a pagar', 'Fecha de pago'], $this->rows(
            'SELECT u.document_id, u.full_name, u.num_cell, u.email, s.orders_count, s.commission_amount, s.conversions_amount,
                    s.adjustments_amount, s.total_amount, s.due_date
             FROM commission_settlements s JOIN users u ON u.id = s.trainer_id
             WHERE s.period_id = ? ORDER BY u.full_name',
            [$periodId]
        ));

        $this->sheet($writer, 'Detalle', ['Pedido', 'Aprobado', 'Entrenador', 'Cupón', 'Cliente', 'Código', 'Producto', 'Cantidad', 'Pagado', 'Base sin IVA', 'IVA', '% comisión', 'Comisión'], $this->rows(
            'SELECT o.order_id, o.approved_at, t.full_name AS trainer, oc.coupon_code, c.full_name AS client, i.product_sku, i.product_name,
                    i.quantity, i.line_total, i.tax_base, i.tax_amount, i.commission_percentage, i.commission_amount
             FROM commission_movements m
             JOIN commission_settlements s ON s.id = m.settlement_id AND s.period_id = ?
             JOIN orders o ON o.order_id = m.order_id
             JOIN orders_items i ON i.order_id = o.order_id
             JOIN orders_coupons oc ON oc.order_id = o.order_id
             JOIN users t ON t.id = m.trainer_id
             JOIN users c ON c.id = o.user_id
             WHERE m.type = \'sale_credit\'
             ORDER BY t.full_name, o.order_id, i.item_id',
            [$periodId]
        ));

        $this->sheet($writer, 'Productos', ['Código', 'Producto', 'Unidades', 'Pagado', 'Base sin IVA', 'IVA', 'Comisión'], $this->rows(
            "SELECT i.product_sku, MAX(i.product_name), SUM(i.quantity), SUM(i.line_total), SUM(i.tax_base), SUM(i.tax_amount), COALESCE(SUM(i.commission_amount), 0)
             FROM orders o JOIN orders_items i ON i.order_id = o.order_id
             WHERE o.approved_at BETWEEN ? AND ? AND o.order_type <> 'redemption'
             GROUP BY i.product_sku ORDER BY SUM(i.line_total) DESC",
            [$from, $to]
        ));

        $this->sheet($writer, 'IVA', ['Tasa de IVA', 'Pagado', 'Base', 'IVA'], $this->rows(
            "SELECT i.tax_rate, SUM(i.line_total), SUM(i.tax_base), SUM(i.tax_amount)
             FROM orders o JOIN orders_items i ON i.order_id = o.order_id
             WHERE o.approved_at BETWEEN ? AND ? AND o.order_type <> 'redemption'
             GROUP BY i.tax_rate ORDER BY i.tax_rate DESC",
            [$from, $to]
        ));

        $this->sheet($writer, 'Puntos', ['Documento', 'Entrenador', 'Emitidos por compras', 'Emitidos por referidos', 'Emitidos por conversión', 'Canjeados', 'Vencidos', 'Ajustes'], $this->rows(
            "SELECT u.document_id, u.full_name,
                    COALESCE(SUM(IF(pm.type = 'credit' AND l.source = 'client_sale', pm.points, 0)), 0),
                    COALESCE(SUM(IF(pm.type = 'credit' AND l.source = 'trainer_referral', pm.points, 0)), 0),
                    COALESCE(SUM(IF(pm.type = 'credit' AND l.source IN ('conversion', 'restitution'), pm.points, 0)), 0),
                    COALESCE(SUM(IF(pm.type = 'redemption', pm.points, 0)), 0),
                    COALESCE(SUM(IF(pm.type = 'expiration', pm.points, 0)), 0),
                    COALESCE(SUM(IF(pm.type = 'adjustment', pm.points, 0)), 0)
             FROM point_movements pm
             JOIN point_lots l ON l.id = pm.point_lot_id
             JOIN users u ON u.id = l.trainer_id
             WHERE pm.created_at BETWEEN ? AND ?
             GROUP BY u.id ORDER BY u.full_name",
            [$from, $to]
        ));

        $this->sheet($writer, 'Canjes', ['Pedido', 'Fecha', 'Documento', 'Entrenador', 'Código', 'Producto', 'Cantidad', 'Puntos'], $this->rows(
            "SELECT o.order_id, o.approved_at, u.document_id, u.full_name, i.product_sku, i.product_name, i.quantity, i.redemption_points
             FROM orders o JOIN orders_items i ON i.order_id = o.order_id JOIN users u ON u.id = o.user_id
             WHERE o.order_type = 'redemption' AND o.approved_at BETWEEN ? AND ?
             ORDER BY o.order_id, i.item_id",
            [$from, $to]
        ));

        $writer->close();

        $old = $this->pathOf($periodId);
        $this->pdo->prepare('UPDATE commission_periods SET file_name = ? WHERE id = ?')->execute([$name, $periodId]);

        if ($old !== null && $old !== $file) {
            @unlink($old);
        }

        return $file;
    }

    /**
     * Envía el resumen a financiero (`COMMISSION_REPORT_RECIPIENTS`), con el enlace al admin. El Excel
     * no va adjunto: trae nombres y comisiones, y solo se descarga con sesión.
     *
     * @return list<string> A quiénes se envió.
     */
    public function sendEmail(int $periodId, ?Mailer $mailer = null): array
    {
        $period     = $this->period($periodId);
        $recipients = array_values(array_filter(array_map(trim(...), explode(',', (string) ($_ENV['COMMISSION_REPORT_RECIPIENTS'] ?? '')))));

        if ($recipients === []) {
            return [];
        }

        $mailer ??= Mailer::fromEnv();
        $label    = CommissionCalendar::label((string) $period['period']);
        $dueDate  = (string) $this->scalar('SELECT MIN(due_date) FROM commission_settlements WHERE period_id = ?', [$periodId]);

        foreach ($recipients as $recipient) {
            $mailer->send(
                MailMessage::to($recipient)
                    ->subject("Cierre de comisión de {$label} · PremiumFitness")
                    ->htmlTemplate('commission-close', [
                        'period_label'   => $label,
                        'trainers_count' => (string) $period['trainers_count'],
                        'orders_count'   => (string) $period['orders_count'],
                        'total_amount'   => '$' . number_format((float) $period['total_amount'], 0, ',', '.'),
                        'due_date'       => $dueDate === '' ? 'No hay pagos este mes' : date('d/m/Y', (int) strtotime($dueDate)),
                        'admin_url'      => rtrim((string) $_ENV['ADMIN_APP_URL'], '/') . '/commission/' . $periodId,
                    ])
            );
        }

        $this->pdo->prepare('UPDATE commission_periods SET report_sent_at = NOW(), report_recipients = ? WHERE id = ?')
            ->execute([mb_substr(implode(',', $recipients), 0, 500), $periodId]);

        return $recipients;
    }

    /** @return array<string, mixed> */
    private function period(int $periodId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM commission_periods WHERE id = ?');
        $stmt->execute([$periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);

        return $period === false ? throw new RuntimeException('Period not found.') : $period;
    }

    /**
     * @param list<string>             $header
     * @param list<list<scalar|null>>  $rows
     */
    private function sheet(Writer $writer, string $name, array $header, array $rows, bool $first = false): void
    {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(self::cell(...), $row)));
        }
    }

    /** Los importes van como número para que Excel los sume; un texto que empieza por = + - @ va como texto. */
    private static function cell(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        $value = (string) $value;

        if (is_numeric($value) && preg_match('/^-?\d{1,12}(\.\d+)?$/', $value) === 1 && !preg_match('/^0\d/', $value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * @param list<mixed> $params
     * @return list<list<scalar|null>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): float|string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return is_numeric($value) ? (float) $value : (string) $value;
    }
}
