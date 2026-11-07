import type { Request, Response } from 'express';

/**
 * Webhook de confirmación — ADDI en Node.js / TypeScript (Express)
 *
 * Addi envía un JSON body via POST.
 * No incluye firma criptográfica; proteger con IP whitelist, token en header o secreto compartido.
 */

export interface AddiWebhookPayload {
  orderId: string | number;
  applicationId: string;
  approvedAmount: number;
  status: 'APPROVED' | 'REJECTED' | 'PENDING' | 'INTERNAL_ERROR' | 'ABANDONED' | 'DECLINED' | string;
  statusTimestamp?: string;
  currency?: string;
}

export function normalizeAddiStatus(status: string): { code: number; response: string; reason: string } {
  const map: Record<string, { code: number; response: string; reason: string }> = {
    APPROVED: { code: 1, response: 'APROBADA', reason: 'Crédito aprobado al 100%' },
    REJECTED: { code: 2, response: 'PAGO RECHAZADO', reason: 'Crédito rechazado por ADDI' },
    PENDING: { code: 3, response: 'PENDIENTE POR CR', reason: 'En proceso de validación' },
    INTERNAL_ERROR: { code: 4, response: 'ERROR CR', reason: 'Error interno en ADDI' },
    ABANDONED: { code: 10, response: 'Abandonada', reason: 'Tiempo máximo superado' },
    DECLINED: { code: 11, response: 'Cancelada', reason: 'Declinada por el cliente' },
  };

  return map[status] || { code: 0, response: 'DESCONOCIDO', reason: status };
}

export function createAddiWebhookHandler(
  onUpdateOrder: (orderId: number, status: { code: number; response: string; reason: string }, payload: AddiWebhookPayload) => Promise<void>
) {
  return async (req: Request, res: Response): Promise<void> => {
    try {
      const data = req.body as AddiWebhookPayload;

      const orderIdRaw = data.orderId;
      const transactionId = data.applicationId;
      const approvedAmount = data.approvedAmount;
      const rawStatus = data.status;

      if (!orderIdRaw || !transactionId || approvedAmount === undefined || !rawStatus) {
        res.status(400).json({ status: false, message: 'Datos incompletos en webhook de Addi' });
        return;
      }

      const orderId = Number(orderIdRaw);
      if (isNaN(orderId) || orderId <= 0) {
        res.status(400).json({ status: false, message: 'orderId inválido' });
        return;
      }

      const normalizedStatus = normalizeAddiStatus(rawStatus);

      await onUpdateOrder(orderId, normalizedStatus, data);

      res.status(200).json({ status: true, message: 'Webhook Addi procesado exitosamente' });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Error interno al procesar webhook Addi';
      res.status(500).json({ status: false, message });
    }
  };
}
