import { createPaymentRequestBody, CredinetPaymentRequestInput } from './helpers/createPaymentRequestBody';

/**
 * Plantilla: Crear orden de pago en Sistecrédito (Credinet) en Node.js / TypeScript
 *
 * Flujo:
 * 1. Recibe datos del e-commerce
 * 2. Valida campos obligatorios
 * 3. Construye el body para Credinet via createPaymentRequestBody()
 * 4. POST a https://api.credinet.co/pay/create
 * 5. GET a https://api.credinet.co/pay/GetTransactionResponse
 * 6. Retorna { idTransaccion, paymentRedirectUrl }
 *
 * Ver: ../../references/sistecredito-api.md
 * Ver: ../../references/env-variables.md#sistecredito
 */

export interface CredinetConfig {
  subscriptionKey: string;
  applicationKey: string;
  applicationToken: string;
  urlPostCreateOrder: string;
  urlGetQueryTransaction: string;
  urlResponseSistecredito: string;
  urlResponseLinkPago: string;
  urlConfirmation: string;
}

export interface CredinetCreateOrderResult {
  idTransaccion: string;
  paymentRedirectUrl: string;
}

export class SistecreditoOrderService {
  constructor(private readonly config: CredinetConfig) {}

  public static fromEnv(): SistecreditoOrderService {
    return new SistecreditoOrderService({
      subscriptionKey: process.env.SUBSCRIPTION_KEY || '',
      applicationKey: process.env.APPLICATION_KEY || '',
      applicationToken: process.env.APPLICATION_TOKEN || '',
      urlPostCreateOrder: process.env.URL_POST_CREATE_ORDER || 'https://api.credinet.co/pay/create',
      urlGetQueryTransaction: process.env.URL_GET_QUERY_TRANSACTION || 'https://api.credinet.co/pay/GetTransactionResponse',
      urlResponseSistecredito: process.env.URL_RESPONSE_SISTECREDITO || '',
      urlResponseLinkPago: process.env.URL_RESPONSE_SISTECREDITO_LINKPAGO || '',
      urlConfirmation: process.env.URL_CONFIRMATION_SITECREDITO || '',
    });
  }

  public getHeaders(): Record<string, string> {
    return {
      SCOrigen: 'Production',
      country: 'co',
      'Content-Type': 'application/json',
      'Ocp-Apim-Subscription-Key': this.config.subscriptionKey,
      ApplicationKey: this.config.applicationKey,
      ApplicationToken: this.config.applicationToken,
      SCLocation: '0,0',
      'User-Agent': 'NodeApp/1.0',
    };
  }

  public async queryTransaction(idTransaccion: string): Promise<any> {
    const url = `${this.config.urlGetQueryTransaction}?transactionId=${encodeURIComponent(idTransaccion)}`;

    const response = await fetch(url, {
      method: 'GET',
      headers: this.getHeaders(),
    });

    if (!response.ok) {
      throw new Error(`Error HTTP ${response.status} al consultar transacción en Credinet`);
    }

    const data = (await response.json()) as any;
    if (!data || !data.data) {
      throw new Error("La respuesta de consulta no contiene el campo 'data'");
    }

    return data;
  }

  public async createOrder(params: {
    invoice: string;
    description: string;
    value: number;
    docType: 'CC' | 'CE' | 'NIT' | 'TI' | 'PP';
    document: string;
    mode?: '1' | '2';
    urlResponseOverride?: string;
  }): Promise<CredinetCreateOrderResult> {
    const urlResponse =
      params.urlResponseOverride ||
      (params.mode === '2' ? this.config.urlResponseLinkPago : this.config.urlResponseSistecredito);

    const requestBody = createPaymentRequestBody({
      invoice: params.invoice,
      description: params.description,
      paymentMethodId: 2,
      currency: 'COP',
      value: params.value,
      sandboxIsActive: false,
      sandboxStatus: 'Approved',
      urlResponse,
      urlConfirmation: this.config.urlConfirmation,
      methodConfirmation: 'POST',
      docType: params.docType,
      document: params.document,
    });

    const response = await fetch(this.config.urlPostCreateOrder, {
      method: 'POST',
      headers: this.getHeaders(),
      body: JSON.stringify(requestBody),
    });

    if (!response.ok) {
      const errText = await response.text();
      throw new Error(`Error HTTP ${response.status} en Sistecrédito: ${errText}`);
    }

    const json = (await response.json()) as any;
    const idTransaccion = json?.data?._id;

    if (!idTransaccion) {
      throw new Error('Error al obtener el id de transacción de Credinet');
    }

    // Consultar la transacción para obtener la URL de redirección
    const queryResult = await this.queryTransaction(idTransaccion);
    const paymentRedirectUrl =
      queryResult?.data?.paymentMethodResponse?.paymentRedirectUrl ||
      queryResult?.paymentMethodResponse?.paymentRedirectUrl ||
      '';

    return {
      idTransaccion,
      paymentRedirectUrl,
    };
  }
}
