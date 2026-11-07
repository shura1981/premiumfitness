import crypto from 'node:crypto';
import type { Request, Response } from 'express';

/**
 * Plantilla: Webhook de Confirmación para Bold (Botón de Pagos) en Node.js / TypeScript (Express)
 *
 * Características clave:
 * 1. Verificación obligatoria de firma `x-bold-signature` mediante HMAC-SHA256
 *    sobre `base64_encode(rawBody)` con `BOLD_SECRET_KEY`.
 *    IMPORTANTE: Requiere el rawBody Buffer (usando express.raw() o verify callback en express.json()).
 * 2. Manejo explícito de eventos:
 *    - `SALE_APPROVED` -> Aprobada (`x_cod_response = 1`)
 *    - `SALE_REJECTED` -> Rechazada (`x_cod_response = 2`)
 *    - `VOID_APPROVED` -> Anulación aprobada / Reversada (`x_cod_response = 6`)
 *    - `VOID_REJECTED` -> Anulación rechazada: No-op (`200 OK`), conserva el estado actual de la orden
 * 3. Mapeo de medios de pago (`CARD`, `CARD_WEB`, `BOLD_TAP`, `PSE`, `NEQUI`, `BOTON_BANCOLOMBIA`, `QR`).
 */

export interface BoldWebhookParsedResult {
  order_id: number;
  payment_id: string;
  event_type: string;
  canonical: Record<string, unknown>;
}

export class BoldWebhookHandler {
  constructor(private readonly secretKey: string) {}

  public static fromEnv(): BoldWebhookHandler {
    return new BoldWebhookHandler(process.env.BOLD_SECRET_KEY || '');
  }

  /**
   * Verifica la firma `x-bold-signature` sobre el cuerpo crudo exacto de la petición.
   *
   * Fórmula oficial Bold:
   *   const encoded = rawBody.toString('base64');
   *   const expected = crypto.createHmac('sha256', secretKey).update(encoded).digest('hex');
   */
  public verifySignature(rawBody: Buffer | string, signatureHeader: string): boolean {
    const received = signatureHeader.trim().toLowerCase();
    if (!received || !this.secretKey) {
      return false;
    }

    const rawBuffer = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(rawBody, 'utf8');
    const encoded = rawBuffer.toString('base64');
    const expected = crypto.createHmac('sha256', this.secretKey).update(encoded).digest('hex');

    if (expected.length !== received.length) {
      return false;
    }

    return crypto.timingSafeEqual(Buffer.from(expected, 'utf8'), Buffer.from(received, 'utf8'));
  }

  public isVoidRejected(payload: Record<string, unknown>): boolean {
    return String(payload.type || '').trim().toUpperCase() === 'VOID_REJECTED';
  }

  public parseWebhookPayload(payload: Record<string, any>): BoldWebhookParsedResult {
    const eventType = String(payload.type || '').trim().toUpperCase();

    if (!['SALE_APPROVED', 'SALE_REJECTED', 'VOID_APPROVED'].includes(eventType)) {
      throw new Error(`Evento de Bold no procesable para cambio de estado: ${eventType}`);
    }

    const data = payload.data || {};
    const metadata = data.metadata || {};
    const amountData = data.amount || {};
    const cardData = data.card || {};

    const reference = String(metadata.reference || '').trim();
    if (!reference || isNaN(Number(reference)) || Number(reference) <= 0) {
      throw new Error('Falta `data.metadata.reference` válido en el webhook de Bold.');
    }

    const paymentId = String(data.payment_id || payload.subject || '').trim();
    if (!paymentId) {
      throw new Error('Falta `data.payment_id` en el webhook de Bold.');
    }

    const totalAmount = Number(amountData.total) || 0;
    const paymentMethod = String(data.payment_method || 'BOLD').trim().toUpperCase();
    const cardBrand = String(cardData.brand || cardData.franchise || '').trim();

    let codResponse = 2;
    let responseText = 'PAGO RECHAZADO';

    if (eventType === 'SALE_APPROVED') {
      codResponse = 1;
      responseText = 'APROBADA';
    } else if (eventType === 'VOID_APPROVED') {
      codResponse = 6;
      responseText = 'Reversada';
    }

    const franchise = this.resolveFranchise(paymentMethod, cardBrand);
    const formaPagoErp = this.resolveFormaPagoErp(paymentMethod);
    const transactionDate = String(data.created_at || new Date().toISOString().replace('T', ' ').substring(0, 19));

    return {
      order_id: Number(reference),
      payment_id: paymentId,
      event_type: eventType,
      canonical: {
        x_cust_id_cliente: String(data.merchant_id || 'BOLD'),
        x_ref_payco: Number(reference),
        x_id_factura: Number(reference),
        x_id_invoice: reference,
        x_description: `Compra online Bold #${reference}`,
        x_amount: totalAmount,
        x_amount_country: totalAmount,
        x_amount_ok: totalAmount,
        x_tax: 0,
        x_amount_base: 0,
        x_currency_code: 'COP',
        x_bank_name: paymentMethod,
        x_cardnumber: String(cardData.masked_pan || 'N/A'),
        x_quotas: Number(cardData.installments) || 1,
        x_respuesta: responseText,
        x_response: responseText,
        x_approval_code: String(data.approval_number || '000000'),
        x_transaction_id: paymentId,
        x_fecha_transaccion: transactionDate,
        x_transaction_date: transactionDate,
        x_cod_respuesta: codResponse,
        x_cod_response: codResponse,
        x_cod_transaction_state: codResponse,
        x_transaction_state: responseText,
        x_response_reason_text: String(data.bold_code || eventType),
        x_errorcode: String(data.bold_code || '00'),
        x_franchise: franchise,
        x_customer_email: String(data.payer_email || ''),
        x_signature: String(payload.id || paymentId),
        forma_pago: formaPagoErp,
      },
    };
  }

  private resolveFranchise(paymentMethod: string, cardBrand: string): string {
    switch (paymentMethod) {
      case 'CARD':
      case 'CARD_WEB':
      case 'BOLD_TAP':
        return cardBrand || 'CARD';
      case 'BOTON_BANCOLOMBIA':
        return 'BANCOLOMBIA';
      case 'PSE':
        return 'PSE';
      case 'NEQUI':
        return 'NEQUI';
      case 'QR':
        return 'QR';
      default:
        return paymentMethod || 'BOLD';
    }
  }

  private resolveFormaPagoErp(paymentMethod: string): string {
    switch (paymentMethod) {
      case 'CARD':
      case 'CARD_WEB':
      case 'BOLD_TAP':
        return 'TARJETA DE CREDITO';
      case 'BOTON_BANCOLOMBIA':
        return 'BANCOLOMBIA';
      case 'PSE':
        return 'PSE';
      case 'NEQUI':
        return 'NEQUI';
      case 'QR':
        return 'QR';
      default:
        return 'BOLD';
    }
  }
}

/**
 * Middleware / Endpoint handler para Express:
 */
export function createBoldWebhookMiddleware(
  onPaymentProcessed: (parsed: BoldWebhookParsedResult) => Promise<void>
) {
  const handler = BoldWebhookHandler.fromEnv();

  return async (req: Request, res: Response): Promise<void> => {
    const signature = (req.headers['x-bold-signature'] as string) || '';
    const rawBody = (req as any).rawBody || JSON.stringify(req.body);

    if (!handler.verifySignature(rawBody, signature)) {
      res.status(401).json({ status: false, message: 'Firma x-bold-signature inválida' });
      return;
    }

    const payload = req.body;

    if (handler.isVoidRejected(payload)) {
      // VOID_REJECTED es no-op exitoso
      res.status(200).json({ status: true, message: 'VOID_REJECTED recibido sin cambios' });
      return;
    }

    try {
      const parsed = handler.parseWebhookPayload(payload);
      await onPaymentProcessed(parsed);
      res.status(200).json({ status: true, message: 'Notificación procesada' });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Error al procesar webhook';
      res.status(400).json({ status: false, message });
    }
  };
}
