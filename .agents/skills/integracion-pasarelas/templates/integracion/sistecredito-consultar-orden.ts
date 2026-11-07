/**
 * Plantilla: Consultar estado de transacción en Sistecrédito (Credinet) en Node.js / TypeScript
 *
 * Flujo:
 * 1. Recibe idTransaccion como query param
 * 2. GET a https://api.credinet.co/pay/GetTransactionResponse?transactionId={id}
 * 3. Normaliza el estado y retorna la estructura canónica
 *
 * Ver: ../../references/sistecredito-api.md#consultar-orden
 */

export interface NormalizedSistecreditoStatus {
  x_cod_response: number;
  x_response: string;
}

export function normalizarEstadoSistecredito(statusResponse: string): NormalizedSistecreditoStatus {
  const map: Record<string, NormalizedSistecreditoStatus> = {
    Approved: { x_cod_response: 1, x_response: 'APROBADA' },
    Rejected: { x_cod_response: 2, x_response: 'PAGO RECHAZADO' },
    Pending: { x_cod_response: 3, x_response: 'PENDIENTE POR CR' },
    Failed: { x_cod_response: 4, x_response: 'ERROR CR' },
    PendingForPaymentMethod: { x_cod_response: 10, x_response: 'PENDIENTE POR MÉTODO DE PAGO' },
    Cancelled: { x_cod_response: 11, x_response: 'Cancelada' },
  };

  return map[statusResponse] || { x_cod_response: 0, x_response: 'DESCONOCIDO' };
}

export interface SistecreditoCanonicalResponse {
  data: {
    x_cod_response: number;
    x_transaction_date: string;
    x_response: string;
    x_response_reason_text: string;
    x_transaction_id: string;
    x_bank_name: string;
    x_approval_code: string;
    x_id_invoice: string;
    x_amount: number;
    x_currency_code: string;
    urlPayment: string;
  };
}

export function construirRespuestaNormalizada(data: any): SistecreditoCanonicalResponse {
  const pmr = data.paymentMethodResponse || {};
  const status = normalizarEstadoSistecredito(pmr.statusResponse || '');

  return {
    data: {
      x_cod_response: status.x_cod_response,
      x_transaction_date: data.updatedAt || new Date().toISOString().replace('T', ' ').substring(0, 19),
      x_response: status.x_response,
      x_response_reason_text: pmr.statusResponse || '',
      x_transaction_id: pmr.transactionId || '',
      x_bank_name: 'SISTECREDITO',
      x_approval_code: pmr.approvalCode || '',
      x_id_invoice: String(data.invoice || ''),
      x_amount: Number(data.value || 0),
      x_currency_code: data.currency || 'COP',
      urlPayment: pmr.paymentRedirectUrl || '',
    },
  };
}
