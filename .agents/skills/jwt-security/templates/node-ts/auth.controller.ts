import type { Request, Response } from 'express';
import type { CookieOptions } from 'express';
import { tokenService } from './token.service';

const REFRESH_COOKIE_NAME = 'refresh_token';
const REFRESH_TOKEN_TTL_MS = 7 * 24 * 60 * 60 * 1000; // 7 días

const cookieOptions: CookieOptions = {
  httpOnly: true,
  secure: process.env.NODE_ENV === 'production',
  sameSite: 'strict',
  path: '/api/auth', // Limita la cookie al endpoint de auth/refresh
  maxAge: REFRESH_TOKEN_TTL_MS,
};

export class AuthController {
  /**
   * POST /api/auth/login
   */
  async login(req: Request, res: Response): Promise<void> {
    const { username, password } = req.body;

    // TODO: Validar credenciales contra la base de datos real (con hash bcrypt/argon2)
    const isValidUser = username === 'admin' && password === 'secret';

    if (!isValidUser) {
      res.status(401).json({ error: 'Credenciales inválidas' });
      return;
    }

    const user = { id: 1, username: 'admin', role: 'trainer', email: 'admin@fitness.com' };

    // Generar par de tokens
    const tokens = tokenService.generateTokens({
      userId: user.id,
      role: user.role,
      email: user.email,
    });

    // TODO: Guardar tokens.jti o hash del refresh token en DB/Redis para lista blanca / revocación

    // Inyectar cookie HttpOnly para el Refresh Token
    res.cookie(REFRESH_COOKIE_NAME, tokens.refreshToken, cookieOptions);

    // Enviar Access Token en el cuerpo de la respuesta
    res.status(200).json({
      accessToken: tokens.accessToken,
      user: {
        id: user.id,
        username: user.username,
        role: user.role,
      },
    });
  }

  /**
   * POST /api/auth/refresh
   */
  async refresh(req: Request, res: Response): Promise<void> {
    // Requiere middleware cookie-parser en Express
    const refreshToken = req.cookies?.[REFRESH_COOKIE_NAME];

    if (!refreshToken) {
      res.status(401).json({ error: 'Refresh token no proporcionado' });
      return;
    }

    try {
      const decoded = tokenService.verifyRefreshToken(refreshToken);

      // TODO: Verificar en DB/Redis si el jti sigue activo y no fue revocado
      // const isRevoked = await tokenRepo.isRevoked(decoded.jti);
      // if (isRevoked) {
      //   // Alerta: posible reutilización / ataque de repetición. Revocar tokens de la familia del usuario.
      //   res.clearCookie(REFRESH_COOKIE_NAME, cookieOptions);
      //   res.status(403).json({ error: 'Token revocado o reutilizado' });
      //   return;
      // }

      // Rotación del Refresh Token (Refresh Token Rotation):
      const newTokens = tokenService.generateTokens({
        userId: decoded.userId,
      });

      // TODO: Actualizar en DB/Redis (invalidar viejo jti, registrar nuevo jti)

      res.cookie(REFRESH_COOKIE_NAME, newTokens.refreshToken, cookieOptions);

      res.status(200).json({
        accessToken: newTokens.accessToken,
      });
    } catch (error) {
      res.clearCookie(REFRESH_COOKIE_NAME, cookieOptions);
      res.status(403).json({ error: 'Refresh token inválido o expirado' });
    }
  }

  /**
   * POST /api/auth/logout
   */
  async logout(req: Request, res: Response): Promise<void> {
    const refreshToken = req.cookies?.[REFRESH_COOKIE_NAME];

    if (refreshToken) {
      try {
        const decoded = tokenService.verifyRefreshToken(refreshToken);
        // TODO: Marcar el token / jti como revocado en base de datos
      } catch {
        // Si ya expiró, simplemente limpiamos la cookie
      }
    }

    res.clearCookie(REFRESH_COOKIE_NAME, cookieOptions);
    res.status(204).send();
  }
}

export const authController = new AuthController();
