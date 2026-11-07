import jwt, { SignOptions, JwtPayload } from 'jsonwebtoken';
import crypto from 'node:crypto';

export interface UserTokenPayload {
  userId: number | string;
  role?: string;
  email?: string;
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  jti: string;
}

export interface CustomJwtPayload extends JwtPayload {
  userId: number | string;
  role?: string;
  email?: string;
  jti?: string;
}

export class TokenService {
  private readonly accessTokenSecret: string;
  private readonly refreshTokenSecret: string;
  private readonly accessTokenTtl: string;
  private readonly refreshTokenTtl: string;

  constructor() {
    this.accessTokenSecret = process.env.JWT_SECRET || 'default-access-secret-minimum-32-chars';
    this.refreshTokenSecret = process.env.JWT_REFRESH_SECRET || 'default-refresh-secret-minimum-32-chars';
    this.accessTokenTtl = process.env.JWT_ACCESS_TTL || '15m';
    this.refreshTokenTtl = process.env.JWT_REFRESH_TTL || '7d';
  }

  generateTokens(payload: UserTokenPayload): AuthTokens {
    const jti = crypto.randomUUID();

    const accessOptions: SignOptions = {
      expiresIn: this.accessTokenTtl as SignOptions['expiresIn'],
    };

    const refreshOptions: SignOptions = {
      expiresIn: this.refreshTokenTtl as SignOptions['expiresIn'],
      jwtid: jti,
    };

    const accessToken = jwt.sign(payload, this.accessTokenSecret, accessOptions);
    const refreshToken = jwt.sign({ userId: payload.userId }, this.refreshTokenSecret, refreshOptions);

    return {
      accessToken,
      refreshToken,
      jti,
    };
  }

  verifyAccessToken(token: string): CustomJwtPayload {
    return jwt.verify(token, this.accessTokenSecret) as CustomJwtPayload;
  }

  verifyRefreshToken(token: string): CustomJwtPayload {
    return jwt.verify(token, this.refreshTokenSecret) as CustomJwtPayload;
  }
}

export const tokenService = new TokenService();
