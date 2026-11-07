/**
 * Plantilla: Obtener URL de redirección de crédito Addi en Node.js / TypeScript
 *
 * Flujo:
 * 1. Recibe datos completos del pedido desde el e-commerce
 * 2. Obtiene/reutiliza token OAuth 2.0 de auth.addi.com
 * 3. POST pass-through a api.addi.com/v1/online-applications con Bearer token
 * 4. Addi responde con HTTP 302 + header Location
 * 5. Retorna { url: "https://app.addi.com/..." }
 *
 * CRÍTICO: Addi responde HTTP 302. En Node.js `fetch` se debe usar `{ redirect: 'manual' }`
 * para capturar la cabecera `Location`.
 */

export interface AddiTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
}

export interface AddiClientConfig {
  audience: string;
  grantType: string;
  clientId: string;
  clientSecret: string;
  urlGetToken: string;
  urlGetRedirection: string;
}

export class AddiPaymentService {
  private cachedToken: string | null = null;
  private tokenExpiresAt: number = 0;

  constructor(private readonly config: AddiClientConfig) {}

  public static fromEnv(): AddiPaymentService {
    return new AddiPaymentService({
      audience: process.env.ADDI_AUDIENCE || '',
      grantType: process.env.GRANT_TYPE || 'client_credentials',
      clientId: process.env.CLIENT_ID || '',
      clientSecret: process.env.CLIENT_SECRET || '',
      urlGetToken: process.env.URL_GET_TOKEN || 'https://auth.addi.com/oauth/token',
      urlGetRedirection: process.env.URL_GET_REDIRECTION || 'https://api.addi.com/v1/online-applications',
    });
  }

  /**
   * Obtiene un Bearer token OAuth 2.0 de Addi con caché en memoria
   */
  public async getAccessToken(): Promise<string> {
    const now = Math.floor(Date.now() / 1000);

    // Reutilizar token si aún tiene más de 60 segundos de validez
    if (this.cachedToken && this.tokenExpiresAt > now + 60) {
      return this.cachedToken;
    }

    const payload = {
      audience: this.config.audience,
      grant_type: this.config.grantType,
      client_id: this.config.clientId,
      client_secret: this.config.clientSecret,
    };

    const response = await fetch(this.config.urlGetToken, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      const errText = await response.text();
      throw new Error(`Error al obtener token Addi (${response.status}): ${errText}`);
    }

    const data = (await response.json()) as AddiTokenResponse;

    if (!data.access_token) {
      throw new Error('La respuesta de autenticación de Addi no contiene access_token');
    }

    this.cachedToken = data.access_token;
    this.tokenExpiresAt = now + (data.expires_in || 3600);

    return this.cachedToken;
  }

  /**
   * Envía la solicitud de crédito a Addi y captura la URL del header Location (HTTP 302)
   */
  public async getRedirectionUrl(orderPayload: Record<string, unknown>): Promise<string> {
    const token = await this.getAccessToken();

    const response = await fetch(this.config.urlGetRedirection, {
      method: 'POST',
      redirect: 'manual', // IMPORTANTE: manual para capturar HTTP 302 sin seguirlo
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify(orderPayload),
    });

    // Addi responde 302 Redirect con header Location
    const location = response.headers.get('location') || '';

    if (response.status === 302 && location) {
      return location;
    }

    // Si Addi respondiera 200/201 con JSON o enlace en body:
    if (response.ok) {
      const body = (await response.json().catch(() => ({}))) as { url?: string; redirectionUrl?: string };
      return body.url || body.redirectionUrl || location;
    }

    const errorDetails = await response.text();
    throw new Error(`Error al solicitar crédito en Addi (HTTP ${response.status}): ${errorDetails}`);
  }
}
