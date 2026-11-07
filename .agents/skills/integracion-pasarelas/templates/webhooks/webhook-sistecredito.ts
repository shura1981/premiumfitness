import type { Request, Response } from 'express';

/**
 * Webhook de confirmación — SISTECREDITO en Node.js / TypeScript (Express)
 *
 * Sistecrédito envía un JSON body via POST.
 * Los datos de la transacción están anidados en PaymentMethodResponse.
 */

export interface SistecreditoWebhookPayload {
  Invoice: string | number;
  Value: number;
  StoreId?: string;
  Currency?: string;
  PaymentMethodResponse?: {
    TransactionId?: string;
    StatusResponse?: 'Approved' | 'Rejected' | 'Pending' | 'Failed' | 'PendingForPaymentMethod' | 'Cancelled' | string;
    ApprovalCode?: string;
    AuthorizationCode?: string;
  };
}

export function normalizeSistecreditoWebhookStatus(status: string): { code: number; response: string; reason: string } {
  const map: Record<string, { code: number; response: string; reason: string }> = {
    Approved: { code: 1, response: 'APROBADA', reason: 'Crédito aprobado' },
    Rejected: { code: 2, response: 'PAGO RECHAZADO', reason: 'Cliente no aprobado para crédito' },
    Pending: { code: 3, response: 'PENDIENTE POR CR', reason: 'En proceso de validación' },
    Failed: { code: 4, response: 'ERROR CR', reason: 'Error en plataforma Sistecrédito' },
    PendingForPaymentMethod: { code: 10, response: 'PENDIENTE POR MÉTODO DE PAGO', reason: 'En validación de método de pago' },
    Cancelled: { code: 11, response: 'Cancelada', reason: 'Declinada por el cliente' },
  };

  return map[status] || { code: 0, response: 'DESCONOCIDO', reason: status };
}

export function createSistecreditoWebhookHandler(
  onUpdateOrder: (orderId: number, status: { code: number; response: string; reason: string }, payload: SistecreditoWebhookPayload) => Promise<void>
) {
  return async (req: Request, res: Response): Promise<void> => {
    try {
      const data = req.body as SistecreditoWebhookPayload;

      if (!data || !data.PaymentMethodResponse) {
        res.status(400).json({ status: false, message: 'Payload inválido de Sistecrédito' });
        return;
      }

      const pmr = data.PaymentMethodResponse;
      const invoiceRaw = data.Invoice;
      const transactionId = pmr.TransactionId;
      const approvedAmount = data.Value;
      const rawStatus = pmr.StatusResponse;

      if (!invoiceRaw || !transactionId || approvedAmount === undefined || !rawStatus) {
        res.status(400).json({ status: false, message: 'Datos incompletos' });
        return;
      }

      const orderId = Number(invoiceRaw);
      if (isNaN(orderId) || orderId <= 0) {
        res.status(400).json({ status: false, message: 'Invoice / Order ID inválido' });
        return;
      }

      const normalizedStatus = normalizeSistecreditoWebhookStatus(rawStatus);

      await onUpdateOrder(orderId, normalizedStatus, data);

      res.status(200).json({ status: true, message: 'Webhook Sistecrédito procesado' });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Error interno al procesar webhook Sistecrédito';
      res.status(500).json({ status: false, message });
    }
  };
}
