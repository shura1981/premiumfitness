const jwt = require('jsonwebtoken');

const ACCESS_TOKEN_SECRET = process.env.ACCESS_TOKEN_SECRET || 'super-secret-access';
const REFRESH_TOKEN_SECRET = process.env.REFRESH_TOKEN_SECRET || 'super-secret-refresh';
const ACCESS_TOKEN_TTL = '15m'; // 15 minutes
const REFRESH_TOKEN_TTL_MS = 7 * 24 * 60 * 60 * 1000; // 7 days in milliseconds

class AuthController {
    
    async login(req, res) {
        const { username, password } = req.body;

        // TODO: Validate credentials against database
        const isValidUser = (username === 'admin' && password === 'secret');

        if (!isValidUser) {
            return res.status(401).json({ error: 'Invalid credentials' });
        }

        const user = { id: 1, username: 'admin' };

        // Generate Tokens
        const accessToken = jwt.sign({ userId: user.id }, ACCESS_TOKEN_SECRET, { expiresIn: ACCESS_TOKEN_TTL });
        const refreshToken = jwt.sign({ userId: user.id }, REFRESH_TOKEN_SECRET, { expiresIn: '7d' });

        // TODO: Optionally save the refresh token in the database to allow revoking

        // Set HttpOnly Cookie for Refresh Token
        res.cookie('refresh_token', refreshToken, {
            httpOnly: true,
            secure: process.env.NODE_ENV === 'production', // Use secure in production (HTTPS)
            sameSite: 'Strict',
            path: '/api/auth', // Restrict cookie to auth routes (like /api/auth/refresh)
            maxAge: REFRESH_TOKEN_TTL_MS
        });

        // Send Access Token in response body
        res.json({
            access_token: accessToken,
            user: user
        });
    }

    async refresh(req, res) {
        // Requires cookie-parser middleware in Express
        const refreshToken = req.cookies?.refresh_token;

        if (!refreshToken) {
            return res.sendStatus(401);
        }

        try {
            // Verify refresh token
            const decoded = jwt.verify(refreshToken, REFRESH_TOKEN_SECRET);
            
            // TODO: Optionally verify if the refresh token is still valid in your database

            // Generate new Access Token
            const newAccessToken = jwt.sign({ userId: decoded.userId }, ACCESS_TOKEN_SECRET, { expiresIn: ACCESS_TOKEN_TTL });

            res.json({
                access_token: newAccessToken
            });

        } catch (error) {
            return res.sendStatus(403); // Forbidden (invalid token)
        }
    }

    async logout(req, res) {
        res.clearCookie('refresh_token', {
            httpOnly: true,
            secure: process.env.NODE_ENV === 'production',
            sameSite: 'Strict',
            path: '/api/auth'
        });
        res.sendStatus(204);
    }
}

module.exports = new AuthController();
