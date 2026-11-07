import type { Request, Response, NextFunction } from 'express';
import { tokenService, CustomJwtPayload } from './token.service';

declare global {
  namespace Express {
    interface Request {
      user?: CustomJwtPayload;
    }
  }
}

/**
 * Express middleware to authenticate short-lived Access Token from Authorization header
 */
export function authenticateJwt(req: Request, res: Response, next: NextFunction): void {
  const authHeader = req.headers.authorization;

  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    res.status(401).json({ error: 'Acceso no autorizado: falta token Bearer' });
    return;
  }

  const token = authHeader.substring(7).trim();

  try {
    const payload = tokenService.verifyAccessToken(token);
    req.user = payload;
    next();
  } catch (error) {
    res.status(401).json({ error: 'Token inválido o expirado' });
  }
}
