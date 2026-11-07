/**
 * Plantilla: Conciliación Manual y Cron Job de Pagos Pendientes para Bold en Node.js / TypeScript
 *
 * Consulta el API de notificaciones de Bold (`BOLD_URL_NOTIFICATIONS`) tanto por
 * referencia externa (`orderId` con `?is_external_reference=true`) como por `payment_id` directo.
 *
 * Variables de entorno requeridas:
 * - BOLD_IDENTITY_KEY      : Llave de identidad enviada en `Authorization: x-api-key {BOLD_IDENTITY_KEY}`
 * - BOLD_URL_NOTIFICATIONS : https://integrations.api.bold.co/payments/webhook/notifications
 */

export const BOLD_VALID_EVENTS = [
  'SALE_APPROVED',
  'SALE_REJECTED',
  'VOID_APPROVED',
  'VOID_REJECTED',
] as const;

export type BoldEventType = typeof BOLD_VALID_EVENTS[number];

export interface BoldNotificationItem {
  id?: string;
  type: string;
  time?: number;
  data?: {
    payment_id?: string;
    amount?: {
      total?: number;
      currency?: string;
    };
    metadata?: {
      reference?: string;
    };
    [key: string]: unknown;
  };
  [key: string]: unknown;
}

export interface BoldReconciliationResult {
  status: string;
  notification: BoldNotificationItem | null;
  message: string;
}

export class BoldReconciliationClient {
  constructor(
    private readonly identityKey: string,
    private readonly notificationsBaseUrl: string
  ) {}

  public static fromEnv(): BoldReconciliationClient {
    return new BoldReconciliationClient(
      process.env.BOLD_IDENTITY_KEY || '',
      (process.env.BOLD_URL_NOTIFICATIONS || 'https://integrations.api.bold.co/payments/webhook/notifications').replace(/\/+$/, '')
    );
  }

  public buildNotificationsUrl(queryValue: string, isExternalReference: boolean = true): string {
    const encoded = encodeURIComponent(queryValue.trim());
    const suffix = isExternalReference ? '?is_external_reference=true' : '';
    return `${this.notificationsBaseUrl}/${encoded}${suffix}`;
  }

  public buildHeaders(): Record<string, string> {
    return {
      Authorization: `x-api-key ${this.identityKey}`,
      Accept: 'application/json',
    };
  }

  /**
   * Selecciona la notificación relevante cuando existen múltiples intentos (payment_id)
   * o múltiples eventos sobre un mismo intento.
   */
  public selectRelevantNotification(notifications: BoldNotificationItem[]): BoldNotificationItem | null {
    const latestByPaymentId = new Map<string, BoldNotificationItem>();

    for (let i = 0; i < notifications.length; i++) {
      const notification = notifications[i];
      const type = notification.type as BoldEventType;

      if (!BOLD_VALID_EVENTS.includes(type)) {
        continue;
      }

      const paymentId = notification.data?.payment_id?.trim() || `idx_${i}`;
      const time = Number(notification.time) || 0;

      const existing = latestByPaymentId.get(paymentId);
      if (!existing || time >= (Number(existing.time) || 0)) {
        latestByPaymentId.set(paymentId, notification);
      }
    }

    if (latestByPaymentId.size === 0) {
      return null;
    }

    const candidates = Array.from(latestByPaymentId.values());
    const approvedCandidates = candidates.filter((n) => n.type === 'SALE_APPROVED');

    const pool = approvedCandidates.length > 0 ? approvedCandidates : candidates;

    pool.sort((a, b) => (Number(b.time) || 0) - (Number(a.time) || 0));

    return pool[0] || null;
  }

  /**
   * Consulta el API de Bold mediante fetch nativo y concilia la orden
   */
  public async reconcile(queryValue: string, isExternalReference: boolean = true): Promise<BoldReconciliationResult> {
    const url = this.buildNotificationsUrl(queryValue, isExternalReference);

    const response = await fetch(url, {
      method: 'GET',
      headers: this.buildHeaders(),
    });

    if (response.status === 404) {
      return {
        status: 'SIN_NOTIFICACION',
        notification: null,
        message: 'Bold aún no registra notificaciones para esta referencia.',
      };
    }

    if (!response.ok) {
      throw new Error(`Error HTTP ${response.status} al consultar notificaciones en Bold.`);
    }

    const body = (await response.json()) as { notifications?: BoldNotificationItem[] };
    const notifications = Array.isArray(body.notifications) ? body.notifications : [];

    if (notifications.length === 0) {
      return {
        status: 'SIN_NOTIFICACION',
        notification: null,
        message: 'Lista de notificaciones vacía en Bold.',
      };
    }

    const selected = this.selectRelevantNotification(notifications);

    if (!selected) {
      return {
        status: 'SIN_NOTIFICACION',
        notification: null,
        message: 'No se encontraron eventos válidos en las notificaciones de Bold.',
      };
    }

    return {
      status: selected.type || 'UNKNOWN',
      notification: selected,
      message: 'Notificación encontrada.',
    };
  }
}
