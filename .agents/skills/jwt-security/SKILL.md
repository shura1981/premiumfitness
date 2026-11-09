---
name: jwt-security
description: Toolkit for implementing secure JWT authentication using short-lived Access Tokens in memory and long-lived Refresh Tokens in httpOnly cookies. Use when asked to implement login, authentication, secure tokens, or fix XSS vulnerabilities in token storage. Provides templates for PHP Slim, Node.js, and Angular frontend.
---

# Secure JWT Authentication

This skill provides the architecture and templates to implement a highly secure JWT authentication flow. It mitigates Cross-Site Scripting (XSS) attacks by never storing sensitive tokens in `localStorage` or `sessionStorage`.

## Architectural Pattern: "The Shielded Architecture"

The responsibility of authentication is divided into two distinct tokens:

1.  **Access Token:**
    *   **Lifespan:** Very short (e.g., 10 to 15 minutes).
    *   **Storage:** Stored **only in application memory** (state variable on the frontend). It must NEVER be saved to `localStorage` or `sessionStorage`.
    *   **Delivery:** Returned in the JSON response body of the login/refresh endpoints.

2.  **Refresh Token:**
    *   **Lifespan:** Longer duration (e.g., 7 days).
    *   **Storage:** Handled by the browser in an `httpOnly`, `Secure`, and `SameSite=Strict` cookie.
    *   **Delivery:** Set via the `Set-Cookie` HTTP header by the backend. JavaScript cannot read this cookie, neutralizing XSS risks for the refresh token.

## Renewal Flow

1.  The frontend uses the Access Token from memory for API requests (via an `Authorization: Bearer <token>` header).
2.  When the Access Token expires (or the app is reloaded and memory is cleared), the API returns a `401 Unauthorized`.
3.  The frontend catches this and makes a silent request to a specific `/refresh` endpoint.
4.  The browser automatically includes the `httpOnly` Refresh Token cookie in this request.
5.  The backend validates the Refresh Token cookie. If valid, it returns a *new* Access Token in the JSON response (and optionally rotates the Refresh Token cookie).
6.  The frontend updates its in-memory Access Token and retries the failed request.

## Refresh Token Validations (Backend)

When the backend automatically receives the `httpOnly` Refresh Token cookie at the `/refresh` endpoint, it **MUST** perform strict validations before issuing a new Access Token:

1. **Cryptographic Signature:** Verify the JWT signature using the server's dedicated Refresh Token Secret to ensure the token was not tampered with or fabricated.
2. **Expiration Claim (`exp`):** Ensure the token's lifetime has not expired. If expired, reject the request and force a hard re-login.
3. **Database Validation / Revocation (Crucial):**
   * Do not rely solely on the token's math (stateless). Query the database to verify the token's unique ID (`jti`) or the token string itself is still valid (whitelisted) and not revoked (blacklisted).
   * This allows invalidating sessions globally if a user changes their password, is banned, or logs out from another device.
4. **Refresh Token Rotation & Reuse Detection (High Security):**
   * Every time a refresh token is successfully used, issue a *new* refresh token (Rotation) and invalidate the old one.
   * If a previously invalidated refresh token is presented again, it strongly suggests a stolen token (Replay Attack). The backend should immediately intercept this and **revoke all active tokens** for that user family.
5. **Subject Identity Validation:** Ensure the user associated with the token's `userId` still exists and possesses an active account status in the database.

## When to Use This Skill

*   When setting up a new authentication system.
*   When migrating away from storing JWTs in `localStorage`.
*   When fixing security vulnerabilities related to session hijacking or XSS.
*   When implementing a "keep me logged in" feature securely.

## Step-by-Step Implementation

1.  **Backend Login:** Generate both tokens. Send the Access Token in the JSON body and the Refresh Token in an `httpOnly` cookie.
2.  **Backend Refresh:** Create an endpoint that reads the `httpOnly` cookie, validates the Refresh Token, and issues a new Access Token.
3.  **Backend Logout:** Create an endpoint that clears the `httpOnly` cookie.
4.  **Frontend State:** Store the Access Token in a memory variable (e.g., Angular Signal, React Context, Redux).
5.  **Frontend Interceptor:** Intercept outgoing requests to attach the Access Token. Intercept `401` responses to trigger the silent refresh flow.

## Provided Templates

You can use the following templates as starting points:

*   [PHP Slim Backend Template](./templates/php-slim/AuthController.php)
*   [Node.js Backend Template](./templates/node-js/auth.controller.js)
*   [Angular Frontend Interceptor](./templates/frontend/auth.interceptor.ts)
*   [Angular Frontend Service](./templates/frontend/auth.service.ts)
*   [Vanilla JS Frontend Fetch Wrapper](./templates/frontend/auth-vanilla.js)
