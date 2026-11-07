import { Injectable, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap, catchError, of, throwError } from 'rxjs';
import { environment } from '@env/environment';

export interface AuthResponse {
  access_token: string;
  user: any;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  // In-memory state for the access token (Never stored in localStorage)
  private state = signal<{ accessToken: string | null, user: any | null }>({
    accessToken: null,
    user: null
  });

  public readonly accessToken = computed(() => this.state().accessToken);
  public readonly currentUser = computed(() => this.state().user);
  public readonly isAuthenticated = computed(() => !!this.state().accessToken);

  constructor(private http: HttpClient) {}

  login(credentials: any): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${environment.apiUrl}/auth/login`, credentials).pipe(
      tap(response => {
        // Store access token in memory only
        this.state.set({
          accessToken: response.access_token,
          user: response.user
        });
      })
    );
  }

  refreshToken(): Observable<{ access_token: string }> {
    // The browser will automatically send the HttpOnly 'refresh_token' cookie 
    // to this endpoint if withCredentials is true (handled in the interceptor).
    return this.http.post<{ access_token: string }>(`${environment.apiUrl}/auth/refresh`, {}, { withCredentials: true }).pipe(
      tap(response => {
        this.state.update(state => ({ ...state, accessToken: response.access_token }));
      }),
      catchError(error => {
        this.logoutLocal();
        return throwError(() => error);
      })
    );
  }

  logout(): Observable<any> {
    return this.http.post(`${environment.apiUrl}/auth/logout`, {}, { withCredentials: true }).pipe(
      tap(() => this.logoutLocal()),
      catchError(() => {
        this.logoutLocal();
        return of(null);
      })
    );
  }

  logoutLocal(): void {
    this.state.set({ accessToken: null, user: null });
    // Redirect to login page or handle state change
  }
}
