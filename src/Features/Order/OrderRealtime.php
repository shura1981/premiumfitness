<?php

declare(strict_types=1);

namespace App\Features\Order;

use App\Features\User\UserRepository;
use App\Infrastructure\Notification\RealtimeGateway;
use App\Infrastructure\Notification\SocketGatewayClient;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Avisos por el socket de elinut (spec integraciones/03), como en radical:
 *
 *   - al admin (todos los agentes conectados): `order.created`, `order.processed`, `order.tracking.updated`
 *     y `order.status.changed`;
 *   - al cliente o entrenador dueño del pedido (su conexión, `users.socket_token`): `order.updated`.
 *
 * El aviso del pago (`purchase.status.changed` y `order.status.changed`) lo mandan los handlers del webhook.
 * Ningún aviso es crítico: si el gateway falla, la operación ya está hecha y se ve al recargar.
 */
final class OrderRealtime
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private ?RealtimeGateway $socket = null,
    ) {}

    /** Pedido nuevo (compra creada o canje): el admin lo agrega a su lista. */
    public function created(int $orderId): void
    {
        $this->safely(function () use ($orderId): void {
            $order = $this->orders->findById($orderId);

            if ($order !== false) {
                $this->socket()->broadcastToAdmin([['event' => 'order.created', 'payload' => $order]]);
            }
        });
    }

    /** Se registró el pedido de SAP: sale de la cola del admin y el cliente lo ve «Preparando». */
    public function processed(int $orderId): void
    {
        $this->safely(fn () => $this->socket()->broadcastToAdmin([['event' => 'order.processed', 'payload' => ['order_id' => $orderId]]]));
        $this->updated($orderId);
    }

    /** Se registró la guía: el admin la ve y el cliente ve el pedido «En camino». */
    public function trackingUpdated(int $orderId): void
    {
        $this->safely(function () use ($orderId): void {
            $order = $this->orders->findById($orderId);

            if ($order !== false) {
                $this->socket()->broadcastToAdmin([['event' => 'order.tracking.updated', 'payload' => [
                    'order_id'        => $orderId,
                    'tracking_number' => $order->tracking_number,
                    'carrier_name'    => $order->carrier_name,
                ]]]);
            }
        });
        $this->updated($orderId);
    }

    /** El estado del pedido cambió fuera del pago (cancelación o devolución): admin y dueño. */
    public function statusChanged(int $orderId): void
    {
        $this->safely(function () use ($orderId): void {
            $order = $this->orders->findById($orderId);

            if ($order !== false) {
                $this->socket()->broadcastToAdmin([['event' => 'order.status.changed', 'payload' => [
                    'order_id'     => $orderId,
                    'status_id'    => $order->status_id,
                    'status_value' => $order->status_value,
                ]]]);
            }
        });
        $this->updated($orderId);
    }

    /** Aviso al dueño del pedido, con el estado tal como lo muestra la app. */
    private function updated(int $orderId): void
    {
        $this->safely(function () use ($orderId): void {
            $order = $this->orders->findById($orderId);
            $token = $order === false ? null : $this->users->findSocketTokenById($order->user_id);

            if ($order === false || $token === null || trim($token) === '') {
                return;
            }

            $this->socket()->emitToSocket($token, [['event' => 'order.updated', 'payload' => [
                'order_id'        => $orderId,
                'status'          => OrderStatusMapper::forUser(
                    $order->status_value,
                    $order->approved_at !== null,
                    (int) $order->sap_order_code > 0,
                    trim((string) $order->tracking_number) !== '',
                ),
                'tracking_number' => $order->tracking_number,
                'carrier_name'    => $order->carrier_name,
                'occurred_at'     => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ]]]);
        });
    }

    private function socket(): RealtimeGateway
    {
        return $this->socket ??= SocketGatewayClient::fromEnv();
    }

    /** @param callable(): void $send */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable) {
            // No crítico: la operación ya está hecha.
        }
    }
}
