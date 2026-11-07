/**
 * Plantilla: Crear sesión de pago en ePayco en Node.js / TypeScript
 *
 * Flujo:
 * 1. Recibe datos del e-commerce (invoice, amount, currency, etc.)
 * 2. Valida campos obligatorios
 * 3. Obtiene/refresca el Bearer token (caché 15 min en memoria)
 * 4. POST a https://apify.epayco.co/payment/session/create
 * 5. Retorna { sessionId }
 *
 * El frontend usa el sessionId para inicializar el checkout de ePayco.
 */

export interface EpaycoSessionConfig {
  publicKey: string;
  privateKey: string;
  pCustIdCliente: string;
  baseUrl?: string;
}

export interface EpaycoSessionInput {
  invoice: string;
  currency?: string;
  amount: number;
  taxBase?: number;
  tax?: number;
  ico?: number;
  description: string;
  urlResponse?: string;
  urlConfirmation?: string;
  methodConfirmation?: 'GET' | 'POST';
  extra1?: string;
  extra2?: string;
  extra3?: string;
}

export class EpaycoSessionService {
  private readonly baseUrl: string;
  private cachedToken: string | null = null;
  private tokenExpiresAt: number = 0;

  constructor(private readonly config: EpaycoSessionConfig) {
    this.baseUrl = config.baseUrl || 'https://apify.epayco.co';
  }

  public static fromEnv(): EpaycoSessionService {
    return new EpaycoSessionService({
      publicKey: process.env.PUBLIC_KEY || '',
      privateKey: process.env.PRIVATE_KEY || '',
      pCustIdCliente: process.env.P_CUST_ID_CLIENTE || '',
      baseUrl: process.env.EPAYCO_BASE_URL || 'https://apify.epayco.co',
    });
  }

  private getLoginHeaders(): Record<string, string> {
    const basicAuth = Buffer.from(`${this.config.publicKey}:${this.config.privateKey}`).toString('base64');
    const entityClientId = `'${Buffer.from(this.config.pCustIdCliente).toString('base64')}'`;

    return {
      Authorization: `Basic ${basicAuth}`,
      EntityClientId: entityClientId,
      'Content-Type': 'application/json',
    };
  }

  public async getBearerToken(): Promise<string> {
    const now = Math.floor(Date.now() / 1000);

    if (this.cachedToken && this.tokenExpiresAt > now + 60) {
      return this.cachedToken;
    }

    const response = await fetch(`${this.baseUrl}/login`, {
      method: 'POST',
      headers: this.getLoginHeaders(),
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`Error al obtener Bearer token de ePayco (HTTP ${response.status}): ${errorText}`);
    }

    const data = (await response.json()) as { token?: string };

    if (!data.token) {
      throw new Error('Respuesta de login ePayco sin token');
    }

    this.cachedToken = data.token;
    this.tokenExpiresAt = now + 900; // 15 minutos TTL

    return this.cachedToken;
  }

  public async createSession(input: EpaycoSessionInput): Promise<string> {
    const token = await this.getBearerToken();

    const payload = {
      invoice: input.invoice,
      currency: input.currency || 'COP',
      amount: input.amount,
      tax_base: input.taxBase ?? 0,
      tax: input.tax ?? 0,
      ico: input.ico ?? 0,
      description: input.description,
      url_response: input.urlResponse || process.env.URL_RESPONSE_EPAYCO,
      url_confirmation: input.urlConfirmation || process.env.URL_CONFIRMATION_EPAYCO,
      method_confirmation: input.methodConfirmation || 'POST',
      extra1: input.extra1 || '',
      extra2: input.extra2 || '',
      extra3: input.extra3 || '',
    };

    const response = await fetch(`${this.baseUrl}/payment/session/create`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      const err = await response.text();
      throw new Error(`Error al crear sesión en ePayco (HTTP ${response.status}): ${err}`);
    }

    const resData = (await response.json()) as { data?: { sessionId?: string }; sessionId?: string };
    const sessionId = resData.data?.sessionId || resData.sessionId;

    if (!sessionId) {
      throw new Error('ePayco no retornó sessionId en la creación de sesión');
    }

    return sessionId;
  }
}
