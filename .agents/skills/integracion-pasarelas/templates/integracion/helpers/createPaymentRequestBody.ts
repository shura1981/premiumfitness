/**
 * Helper: Construye el body JSON para la solicitud de pago a Credinet (Sistecrédito) en Node.js / TypeScript
 *
 * Ver: ../../../references/sistecredito-api.md#crear-orden
 */

export interface CredinetPaymentRequestInput {
  invoice: string;
  description: string;
  paymentMethodId?: number; // Siempre 2 para Sistecrédito
  currency?: string; // Siempre COP
  value: number; // Monto sin decimales
  sandboxIsActive?: boolean;
  sandboxStatus?: 'Approved' | 'Rejected' | 'Pending';
  urlResponse: string;
  urlConfirmation: string;
  methodConfirmation?: 'POST';
  docType: 'CC' | 'CE' | 'NIT' | 'TI' | 'PP';
  document: string;
  authentication?: string;
}

export interface CredinetPaymentRequestBody {
  invoice: string;
  description: string;
  paymentMethod: {
    paymentMethodId: number;
  };
  currency: string;
  value: number;
  sandbox: {
    isActive: boolean;
    status: string;
  };
  urlResponse: string;
  urlConfirmation: string;
  methodConfirmation: string;
  client: {
    docType: string;
    document: string;
  };
  extraData: {
    Authentication: string;
  };
}

/**
 * Genera el objeto tipado para POST https://api.credinet.co/pay/create
 */
export function createPaymentRequestBody(input: CredinetPaymentRequestInput): CredinetPaymentRequestBody {
  return {
    invoice: input.invoice,
    description: input.description,
    paymentMethod: {
      paymentMethodId: input.paymentMethodId ?? 2,
    },
    currency: input.currency ?? 'COP',
    value: Math.round(input.value),
    sandbox: {
      isActive: Boolean(input.sandboxIsActive),
      status: input.sandboxStatus ?? 'Approved',
    },
    urlResponse: input.urlResponse,
    urlConfirmation: input.urlConfirmation,
    methodConfirmation: input.methodConfirmation ?? 'POST',
    client: {
      docType: input.docType,
      document: input.document,
    },
    extraData: {
      Authentication: input.authentication ?? '',
    },
  };
}
