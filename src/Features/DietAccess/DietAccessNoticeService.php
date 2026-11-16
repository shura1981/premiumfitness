<?php

declare(strict_types=1);

namespace App\Features\DietAccess;

use App\Features\Commission\CommissionCalendar;
use App\Features\Notification\PushOutbox;
use App\Infrastructure\Database\DB;
use DateTimeImmutable;
use PDO;

/**
 * Avisos diarios del acceso a la app de dietas (spec integraciones/01, «Eventos que notifican»):
 *   - el acceso vence en 3 días y, tal como va, el entrenador no lo tendrá después;
 *   - el acceso se desactivó.
 * Lo dispara el cron de cPanel. Cada aviso sale una sola vez (`source_key`).
 */
class DietAccessNoticeService
{
    private const int NOTICE_DAYS = 3;

    /** @return array{ending:int, deactivated:int} */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now     = $now ?? new DateTimeImmutable();
        $service = new DietAccessService();
        $result  = ['ending' => 0, 'deactivated' => 0];

        // Solo quienes ya entraron alguna vez a la app de dietas.
        $trainers = DB::getInstance()->query(
            "SELECT t.user_id FROM trainers t JOIN users u ON u.id = t.user_id
             WHERE t.diet_first_access_at IS NOT NULL AND u.status = 'active' AND u.role = 'trainer'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($trainers as $trainerId) {
            $trainerId = (int) $trainerId;
            $today     = $service->accessAt($trainerId, $now);

            if ($today['has_access']) {
                $after = $today['valid_until']->modify('+1 second');
                $soon  = $today['valid_until'] <= $now->modify('+' . self::NOTICE_DAYS . ' days');

                // Vence pronto y al día siguiente no tendría acceso por otra vía (meta o canje).
                if ($soon && !$service->accessAt($trainerId, $after)['has_access']) {
                    $result['ending'] += $this->notify(
                        $trainerId,
                        'diet_access_ending',
                        'Tu acceso a dietas está por vencer',
                        sprintf('Vence el %s. Cumple tu meta o canjea puntos para conservarlo.', $today['valid_until']->format('j/n')),
                        'diet-ending:' . $trainerId . ':' . $today['valid_until']->format('Y-m-d'),
                    );
                }

                continue;
            }

            // Ayer tenía acceso y hoy no: se desactivó.
            if ($service->accessAt($trainerId, $now->modify('-1 day'))['has_access']) {
                $result['deactivated'] += $this->notify(
                    $trainerId,
                    'diet_access_deactivated',
                    'Tu acceso a dietas se desactivó',
                    'Puedes recuperarlo canjeando puntos o cumpliendo tu meta de este mes.',
                    'diet-off:' . $trainerId . ':' . CommissionCalendar::periodOf($now) . ':' . $now->format('d'),
                );
            }
        }

        return $result;
    }

    private function notify(int $trainerId, string $type, string $title, string $body, string $sourceKey): int
    {
        $exists = DB::getInstance()->prepare('SELECT 1 FROM push_notifications WHERE source_key = ?');
        $exists->execute([$sourceKey]);

        if ($exists->fetchColumn() !== false) {
            return 0;
        }

        PushOutbox::enqueue($trainerId, $type, $title, $body, ['route' => '/entrenador'], $sourceKey);

        return 1;
    }
}
