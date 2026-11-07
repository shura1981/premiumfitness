import crypto from 'node:crypto';
import type { Request, Response } from 'express';

/**
 * Webhook de confirmación — EPAYCO en Node.js / TypeScript (Express)
 *
 * ePayco envía datos en formato JSON (application/json) o form-encoded (application/x-www-form-urlencoded).
 * Valida la firma SHA-256 (x_signature) antes de procesar el pago.
 */

export interface EpaycoWebhookPayload {
  x_ref_payco?: string | number;
  x_id_factura?: string | number;
  x_id_invoice?: string | number;
  x_description?: string;
  x_amount?: string | number;
  x_amount_country?: string | number;
  x_amount_ok?: string | number;
  x_tax?: string | number;
  x_amount_base?: string | number;
  x_currency_code?: string;
  x_bank_name?: string;
  x_cardnumber?: string;
  x_quotas?: string | number;
  x_respuesta?: string;
  x_response?: string;
  x_approval_code?: string;
  x_transaction_id?: string;
  x_fecha_transaccion?: string;
  x_transaction_date?: string;
  x_cod_respuesta?: string | number;
  x_cod_response?: string | number;
  x_response_reason_text?: string;
  x_errorcode?: string;
  x_cod_transaction_state?: string | number;
  x_transaction_state?: string;
  x_franchise?: string;
  x_customer_email?: string;
  x_signature?: string;
  x_cust_id_cliente?: string | number;
}

export function verifyEpaycoSignature(
  pCustIdCliente: string,
  pKey: string,
  xRefPayco: string | number,
  xTransactionId: string,
  xAmount: string | number,
  xCurrencyCode: string,
  receivedSignature: string
): boolean {
  // Fórmula ePayco: sha256(p_cust_id_cliente ^ p_key ^ x_ref_payco ^ x_transaction_id ^ x_amount ^ x_currency_code)
  const plain = `${pCustIdCliente}^${pKey}^${xRefPayco}^${xTransactionId}^${xAmount}^${xCurrencyCode}`;
  const calculated = crypto.createHash('sha256').update(plain, 'utf8').digest('hex');

  return calculated.toLowerCase() === receivedSignature.trim().toLowerCase();
}

export function createEpaycoWebhookHandler(
  pCustIdCliente: string,
  pKey: string,
  onUpdateOrder: (orderId: number, isApproved: boolean, payload: EpaycoWebhookPayload) => Promise<void>
) {
  return async (req: Request, res: Response): Promise<void> => {
    try {
      const data = req.body as EpaycoWebhookPayload;

      const refPayco = data.x_ref_payco || '';
      const txId = data.x_transaction_id || '';
      const amount = data.x_amount || 0;
      const currency = data.x_currency_code || 'COP';
      const signature = data.x_signature || '';
      const invoice = data.x_id_invoice || data.x_id_factura;

      if (!invoice) {
        res.status(400).json({ status: false, message: 'Falta número de factura (x_id_invoice)' });
        return;
      }

      const isValidSignature = verifyEpaycoSignature(
        pCustIdCliente,
        pKey,
        refPayco,
        txId,
        amount,
        currency,
        signature
      );

      if (!isValidSignature) {
        res.status(401).json({ status: false, message: 'Firma x_signature inválida' });
        return;
      }

      const codResponse = Number(data.x_cod_response ?? data.x_cod_respuesta);
      const isApproved = codResponse === 1;

      await onUpdateOrder(Number(invoice), isApproved, data);

      res.status(200).json({ status: true, message: 'Webhook ePayco procesado con éxito' });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Error al procesar webhook ePayco';
      res.status(500).json({ status: false, message });
    }
  };
}
