import crypto from 'node:crypto';

/**
 * Plantilla: Generación de Firma de Integridad (Hash SHA-256) y Configuración para Bold Checkout
 *
 * Basado en la integración de producción con el Botón de Pagos Bold en Node.js / TypeScript:
 * - Genera el hash SHA-256 oficial: crypto.createHash('sha256').update(orderId + amount + currency + secretKey).digest('hex')
 * - Construye la configuración completa para `new BoldCheckout(...)` en el frontend
 *
 * Variables de entorno requeridas:
 * - BOLD_IDENTITY_KEY : Llave pública de identidad (apiKey en el frontend)
 * - BOLD_SECRET_KEY   : Llave secreta del comercio (NUNCA exponer al frontend)
 * - BOLD_URL_RESPONSE : URL base de retorno al finalizar el pago
 */

export interface BoldOrderInput {
  order_id: number | string;
  amount: number | string;
  currency?: string;
  description?: string;
  email?: string;
  full_name?: string;
  phone?: string;
  dial_code?: string;
  document_number?: string;
  document_type?: string;
  address?: string;
  city?: string;
  state?: string;
  country?: string;
}

export interface BoldCheckoutConfig {
  orderId: string;
  currency: string;
  amount: string;
  apiKey: string;
  integritySignature: string;
  description: string;
  redirectionUrl: string;
  renderMode: 'embedded';
  customerData: string; // JSON string
  billingAddress: string; // JSON string
}

export interface BoldCheckoutPayload {
  type: 'bold_checkout';
  gateway: 'bold';
  hash: string;
  checkout: BoldCheckoutConfig;
}

export class BoldCheckoutConfigBuilder {
  constructor(
    private readonly identityKey: string,
    private readonly secretKey: string,
    private readonly responseUrl: string
  ) {}

  public static fromEnv(): BoldCheckoutConfigBuilder {
    return new BoldCheckoutConfigBuilder(
      process.env.BOLD_IDENTITY_KEY || '',
      process.env.BOLD_SECRET_KEY || '',
      process.env.BOLD_URL_RESPONSE || ''
    );
  }

  /**
   * Calcula la firma de integridad SHA-256 exigida por Bold.
   * Regla crítica: orderId y amount deben ser enteros positivos sin decimales.
   */
  public generateIntegrityHash(orderId: number | string, amount: number | string, currency: string = 'COP'): string {
    const normalizedOrderId = String(orderId).trim();
    const normalizedAmount = String(amount).trim();
    const normalizedCurrency = currency.trim().toUpperCase();

    if (!/^[1-9][0-9]*$/.test(normalizedOrderId)) {
      throw new Error('El identificador de pago (orderId) debe ser un entero positivo.');
    }

    if (!/^[1-9][0-9]*$/.test(normalizedAmount)) {
      throw new Error('El monto (amount) debe ser un entero positivo sin decimales.');
    }

    if (normalizedCurrency !== 'COP') {
      throw new Error('La divisa para Bold debe ser COP.');
    }

    if (!this.secretKey) {
      throw new Error('Falta configurar BOLD_SECRET_KEY en las variables de entorno.');
    }

    const payload = `${normalizedOrderId}${normalizedAmount}${normalizedCurrency}${this.secretKey}`;
    return crypto.createHash('sha256').update(payload, 'utf8').digest('hex');
  }

  /**
   * Construye el payload completo para abrir `new BoldCheckout(config)` en el navegador.
   */
  public buildCheckoutPayload(orderData: BoldOrderInput): BoldCheckoutPayload {
    const orderId = String(Math.floor(Number(orderData.order_id)));
    const amount = String(Math.round(Number(orderData.amount)));
    const currency = 'COP';

    const integritySignature = this.generateIntegrityHash(orderId, amount, currency);

    const separator = this.responseUrl.includes('?') ? '&' : '?';
    const redirectionUrl = this.responseUrl
      ? `${this.responseUrl}${separator}orderId=${encodeURIComponent(orderId)}`
      : '';

    const customerData = JSON.stringify({
      email: orderData.email || '',
      fullName: orderData.full_name || '',
      phone: orderData.phone || '',
      dialCode: orderData.dial_code || '+57',
      documentNumber: orderData.document_number || '',
      documentType: orderData.document_type || 'CC',
    });

    const billingAddress = JSON.stringify({
      address: orderData.address || '',
      city: orderData.city || '',
      state: orderData.state || '',
      country: orderData.country || 'CO',
    });

    return {
      type: 'bold_checkout',
      gateway: 'bold',
      hash: integritySignature,
      checkout: {
        orderId,
        currency,
        amount,
        apiKey: this.identityKey,
        integritySignature,
        description: orderData.description || `Compra pedido #${orderId}`,
        redirectionUrl,
        renderMode: 'embedded',
        customerData,
        billingAddress,
      },
    };
  }
}
