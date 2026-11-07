/**
 * Vanilla JS implementation of the Shielded Authentication Architecture.
 * Access tokens are kept strictly in memory.
 * Refresh tokens are handled automatically via httpOnly cookies.
 */

const AuthConfig = {
    loginUrl: '/api/auth/login',
    refreshUrl: '/api/auth/refresh',
    logoutUrl: '/api/auth/logout',
};

// In-memory state (NEVER use localStorage or sessionStorage for this)
let state = {
    accessToken: null,
    user: null,
};

let isRefreshing = false;
let refreshSubscribers = [];

/**
 * Notifies all subscribers that token refresh completed
 * @param {string|null} token The new access token, or null if failed
 */
function onRefreshed(token) {
    refreshSubscribers.forEach(cb => cb(token));
    refreshSubscribers = [];
}

/**
 * Custom Fetch Wrapper that automatically injects the Access Token
 * and handles silent 401 retries via the Refresh Token.
 * Use this instead of the native `fetch` for authenticated routes.
 * 
 * @param {string} url 
 * @param {RequestInit} options 
 * @returns {Promise<Response>}
 */
export async function fetchWithAuth(url, options = {}) {
    // Inject Authorization header if we have an access token
    const headers = new Headers(options.headers || {});
    if (state.accessToken) {
        headers.set('Authorization', `Bearer ${state.accessToken}`);
    }

    const config = {
        ...options,
        headers,
        // Optional: Ensure credentials are sent if making cross-origin requests
        // credentials: 'include' 
    };

    let response = await fetch(url, config);

    // Handle expired access token
    if (response.status === 401 && url !== AuthConfig.loginUrl && url !== AuthConfig.refreshUrl) {
        if (!isRefreshing) {
            isRefreshing = true;
            try {
                const refreshed = await refreshToken();
                if (refreshed) {
                    onRefreshed(state.accessToken);
                } else {
                    onRefreshed(null);
                    logoutLocal();
                }
            } catch (err) {
                onRefreshed(null);
                logoutLocal();
            } finally {
                isRefreshing = false;
            }
        }

        // Wait for the refresh to complete before retrying
        return new Promise((resolve) => {
            refreshSubscribers.push(async (newToken) => {
                if (newToken) {
                    // Retry original request with the new token
                    config.headers.set('Authorization', `Bearer ${newToken}`);
                    resolve(await fetch(url, config));
                } else {
                    resolve(response); // Return original 401 if refresh failed
                }
            });
        });
    }

    return response;
}

/**
 * Authenticates the user and sets the in-memory state.
 * @param {string} username 
 * @param {string} password 
 */
export async function login(username, password) {
    const response = await fetch(AuthConfig.loginUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
    });

    if (response.ok) {
        const data = await response.json();
        // Store access token in memory ONLY
        state.accessToken = data.access_token;
        state.user = data.user;
        return true;
    }
    return false;
}

/**
 * Silently requests a new access token using the httpOnly refresh_token cookie.
 */
export async function refreshToken() {
    try {
        const response = await fetch(AuthConfig.refreshUrl, {
            method: 'POST',
            // credentials: 'include' is required to send the httpOnly cookie automatically
            credentials: 'include' 
        });

        if (response.ok) {
            const data = await response.json();
            state.accessToken = data.access_token;
            return true;
        }
        return false;
    } catch (err) {
        console.error('Refresh token failed:', err);
        return false;
    }
}

/**
 * Logs the user out on both backend (clears cookie) and frontend (clears memory).
 */
export async function logout() {
    await fetch(AuthConfig.logoutUrl, { 
        method: 'POST', 
        credentials: 'include' 
    }).catch(() => {});
    logoutLocal();
}

/**
 * Clears local state and redirects to login
 */
export function logoutLocal() {
    state.accessToken = null;
    state.user = null;
    // e.g., window.location.href = '/login.html';
}

/**
 * Returns the current authenticated state
 */
export function isAuthenticated() {
    return !!state.accessToken;
}

export function getCurrentUser() {
    return state.user;
}
