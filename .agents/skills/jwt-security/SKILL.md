---
name: jwt-security
description: Toolkit for implementing secure JWT authentication using short-lived Access Tokens in memory and long-lived Refresh Tokens in httpOnly cookies. Use when asked to implement login, authentication, secure tokens, or fix XSS vulnerabilities in token storage. Provides templates for Node.js (TypeScript + Express) and Angular frontend.
---

# Secure JWT Authentication (Node.js + TypeScript + Angular)

This skill provides the architecture and templates to implement a highly secure JWT authentication flow. It mitigates Cross-Site Scripting (XSS) attacks by never storing sensitive tokens in `localStorage` or `sessionStorage`.

## Architectural Pattern: "The Shielded Architecture"

The responsibility of authentication is divided into two distinct tokens:

1. **Access Token:**
   - **Lifespan:** Very short (e.g., 10 to 15 minutes).
   - **Storage:** Stored **only in application memory** (state variable or Angular signal/service). It must NEVER be saved to `localStorage` or `sessionStorage`.
   - **Delivery:** Returned in the JSON response body of the login/refresh endpoints.

2. **Refresh Token:**
   - **Lifespan:** Longer duration (e.g., 7 days).
   - **Storage:** Handled by the browser in an `httpOnly`, `Secure`, and `SameSite=Strict` cookie.
   - **Delivery:** Set via the `Set-Cookie` HTTP header by the backend. JavaScript cannot read this cookie, neutralizing XSS risks.

## Renewal Flow

1. The frontend uses the Access Token from memory for API requests (via an `Authorization: Bearer <token>` header).
2. When the Access Token expires (or the app is reloaded and memory is cleared), the API returns a `401 Unauthorized`.
3. The frontend catches this and makes a silent request to `/api/auth/refresh`.
4. The browser automatically includes the `httpOnly` Refresh Token cookie in this request.
5. The backend validates the Refresh Token cookie. If valid, it returns a *new* Access Token in the JSON response and rotates the Refresh Token cookie.
6. The frontend updates its in-memory Access Token and retries the failed request.

## Refresh Token Validations (Backend)

When the backend receives the `httpOnly` Refresh Token cookie at `/api/auth/refresh`, it **MUST** perform strict validations:

1. **Cryptographic Signature:** Verify the JWT signature using the server's dedicated `JWT_REFRESH_SECRET`.
2. **Expiration Claim (`exp`):** Ensure the token's lifetime has not expired.
3. **Database Validation / Revocation:** Query the database/Redis to verify the token's unique ID (`jti`) is still valid and not blacklisted.
4. **Refresh Token Rotation & Reuse Detection:** Every time a refresh token is used, issue a *new* refresh token and invalidate the old one. If an invalidated token is reused, revoke all active tokens for that user family.
5. **Subject Identity Validation:** Ensure the user associated with `userId` exists and remains active.

## When to Use This Skill

- When setting up authentication for Node.js / Express and Angular.
- When migrating away from storing JWTs in `localStorage`.
- When fixing security vulnerabilities related to session hijacking or XSS.
- When implementing a secure "keep me logged in" session renewal mechanism.

## Step-by-Step Implementation

1. **Backend Login:** Generate both tokens. Send the Access Token in JSON and the Refresh Token in an `httpOnly` cookie (`res.cookie('refresh_token', ...)`).
2. **Backend Refresh:** Endpoint reading `req.cookies.refresh_token` (via `cookie-parser`), validating signature & DB status, and issuing rotated tokens.
3. **Backend Logout:** Endpoint clearing the `httpOnly` cookie with `res.clearCookie('refresh_token')`.
4. **Backend Middleware:** Authenticate incoming protected requests via `authenticateJwt` middleware.
5. **Frontend Interceptor:** Angular HTTP interceptor attaching `Authorization: Bearer <token>` and intercepting 401 errors for silent renewal.

## Provided Templates

You can use the following templates as starting points:

- [Node.js + TypeScript Auth Controller](./templates/node-ts/auth.controller.ts)
- [Node.js + TypeScript Token Service](./templates/node-ts/token.service.ts)
- [Node.js + TypeScript Auth Middleware](./templates/node-ts/auth.middleware.ts)
- [Angular Frontend Interceptor](./templates/frontend/auth.interceptor.ts)
- [Angular Frontend Service](./templates/frontend/auth.service.ts)
- [Vanilla JS Frontend Fetch Wrapper](./templates/frontend/auth-vanilla.js)
