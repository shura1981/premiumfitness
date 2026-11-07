import type { Request, Response } from 'express';
import { OtpService, Context } from './otp';

export class OtpController {
  constructor(private readonly otpService: OtpService) {}

  /**
   * POST /api/otp/request
   */
  async requestOtp(req: Request, res: Response): Promise<void> {
    try {
      const { phone, purpose = 'auth' } = req.body;
      const clientIp = (req.headers['x-forwarded-for'] as string)?.split(',')[0] || req.ip || '127.0.0.1';

      if (!phone || typeof phone !== 'string') {
        res.status(400).json({ error: 'Número de teléfono es obligatorio' });
        return;
      }

      // Limpiar y validar número telefónico a formato canónico (ej. +573001234567)
      const cleanPhone = phone.trim();

      const context: Context = {
        tenant: 'premiumfitness',
        subject: cleanPhone,
        destination: cleanPhone,
        purpose,
        session: req.headers['x-session-id'] as string || '',
      };

      const result = await this.otpService.request(context, clientIp);

      if (!result.ok) {
        res.status(429).json({
          error: 'Demasiadas solicitudes. Por favor intente más tarde.',
          retryAfter: result.retryAfter,
        });
        return;
      }

      res.status(200).json({
        success: true,
        challengeId: result.value.id,
        expiresIn: result.value.expiresIn,
        message: 'Código de verificación enviado con éxito',
      });
    } catch (error) {
      res.status(500).json({ error: 'Error interno al solicitar código' });
    }
  }

  /**
   * POST /api/otp/verify
   */
  async verifyOtp(req: Request, res: Response): Promise<void> {
    try {
      const { phone, code, purpose = 'auth' } = req.body;
      const clientIp = (req.headers['x-forwarded-for'] as string)?.split(',')[0] || req.ip || '127.0.0.1';

      if (!phone || !code) {
        res.status(400).json({ error: 'Teléfono y código son requeridos' });
        return;
      }

      const cleanPhone = phone.trim();

      const context: Context = {
        tenant: 'premiumfitness',
        subject: cleanPhone,
        destination: cleanPhone,
        purpose,
        session: req.headers['x-session-id'] as string || '',
      };

      const result = await this.otpService.verify(context, String(code).trim(), clientIp);

      if (!result.ok) {
        if (result.reason === 'limited') {
          res.status(429).json({
            error: 'Límite de intentos excedido. Espere antes de reintentar.',
            retryAfter: result.retryAfter,
          });
          return;
        }

        res.status(400).json({
          error: 'Código inválido o expirado',
        });
        return;
      }

      res.status(200).json({
        success: true,
        verified: true,
        message: 'Verificación exitosa',
      });
    } catch (error) {
      res.status(500).json({ error: 'Error interno al verificar código' });
    }
  }
}
