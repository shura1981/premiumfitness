import { EpaycoSessionService } from './epayco-crear-sesion';

/**
 * Plantilla: Detalle y Reversión de Transacciones en ePayco en Node.js / TypeScript
 */

export interface EpaycoTransactionDetail {
  ref_payco: number | string;
  factura: string;
  descripcion: string;
  valor: number;
  iva: number;
  ico: number;
  baseiva: number;
  moneda: string;
  banco: string;
  estado: string;
  respuesta: string;
  autorizacion: string;
  recibo: string;
  fecha: string;
  franquicia: string;
  cod_respuesta: number;
  ip: string;
  enpruebas: number;
}

export class EpaycoTransactionService {
  constructor(private readonly sessionService: EpaycoSessionService) {}

  public static fromEnv(): EpaycoTransactionService {
    return new EpaycoTransactionService(EpaycoSessionService.fromEnv());
  }

  /**
   * Consulta el detalle de una transacción por su referencia `ref_payco`
   */
  public async getTransactionDetail(refPayco: string | number): Promise<EpaycoTransactionDetail> {
    const url = `https://secure.epayco.co/validation/v1/reference/${encodeURIComponent(String(refPayco))}`;

    const response = await fetch(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
      },
    });

    if (!response.ok) {
      throw new Error(`Error HTTP ${response.status} al consultar transacción en ePayco`);
    }

    const json = (await response.json()) as { success?: boolean; data?: EpaycoTransactionDetail };

    if (!json.data) {
      throw new Error('Respuesta inválida de ePayco al consultar transacción');
    }

    return json.data;
  }

  /**
   * Solicita la reversión de una transacción a través de la API
   */
  public async refundTransaction(reference: string | number): Promise<boolean> {
    const token = await this.sessionService.getBearerToken();

    const response = await fetch('https://apify.epayco.co/transaction/revert', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ reference }),
    });

    if (!response.ok) {
      const err = await response.text();
      throw new Error(`Error al solicitar reversión en ePayco: ${err}`);
    }

    const data = (await response.json()) as { status?: boolean; success?: boolean };
    return Boolean(data.status || data.success);
  }
}
