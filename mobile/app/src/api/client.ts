import Constants from 'expo-constants';
import { Platform } from 'react-native';

import { readToken, clearToken } from '@/storage/secure';
import { readCache, writeCache } from '@/storage/cache';

/**
 * The one way this app talks to the server.
 *
 * Every rule the API enforces is mirrored here as an EXPECTATION, never as a
 * decision: the app renders what it is given (spec §1 principle 4). In
 * particular the `capabilities` array on a child is a rendering contract - we
 * hide a tile whose code is absent - and it is NEVER treated as permission.
 * The server re-checks on every endpoint, so a stale capability list can only
 * ever cause a wasted request, not a leak.
 */

const extra = Constants.expoConfig?.extra as
  | { apiBaseUrl?: string; apiWebBaseUrl?: string }
  | undefined;

// `apiBaseUrl` is written for a native emulator (10.0.2.2 is Android's alias
// for the host machine's localhost). The web preview runs inside the host's
// own browser, so 10.0.2.2 doesn't resolve there - it needs the host's
// address directly, which `apiWebBaseUrl` carries separately.
const baseUrl: string =
  Platform.OS === 'web'
    ? (extra?.apiWebBaseUrl ?? 'http://localhost:8931/api/v1')
    : (extra?.apiBaseUrl ?? 'http://localhost:8000/api/v1');

export type ApiErrorCode =
  | 'unauthenticated'
  | 'token_expired'
  | 'invalid_credentials'
  | 'capability_denied'
  | 'not_found'
  | 'conflict'
  | 'validation_failed'
  | 'rate_limited'
  | 'not_implemented'
  | 'offline'
  | 'unknown';

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: ApiErrorCode,
    message: string,
    readonly details: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The app must sign the user out and stop retrying. */
  get isAuthFailure(): boolean {
    return this.status === 401;
  }

  /**
   * The server says this parent may not see this. Distinct from a 404, which
   * on this API means "there is no such child for you" - the app must render
   * those differently or it teaches parents to distrust the 404.
   */
  get isDenied(): boolean {
    return this.status === 403;
  }
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Sent as `Idempotency-Key`; mandatory on the writes the spec names. */
  idempotencyKey?: string;
  language?: 'en' | 'fr';
  signal?: AbortSignal;
};

function decodeError(status: number, payload: unknown): ApiError {
  const envelope = payload as
    | { error?: { code?: string; message?: string; details?: Record<string, string[]> } }
    | { message?: string; errors?: Record<string, string[]> }
    | undefined;

  if (envelope && 'error' in envelope && envelope.error) {
    return new ApiError(
      status,
      (envelope.error.code as ApiErrorCode) ?? 'unknown',
      envelope.error.message ?? 'Something went wrong.',
      envelope.error.details ?? {},
    );
  }

  // Laravel's framework-shaped 422, which the sign-in endpoint also uses as its
  // single deliberately-uninformative answer to every credential failure.
  if (envelope && 'message' in envelope) {
    return new ApiError(
      status,
      status === 422 ? 'validation_failed' : 'unknown',
      envelope.message ?? 'Something went wrong.',
      ('errors' in envelope ? envelope.errors : undefined) ?? {},
    );
  }

  return new ApiError(status, 'unknown', 'Something went wrong.');
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const token = await readToken();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  if (token) headers.Authorization = `Bearer ${token.value}`;
  if (options.language) headers['Accept-Language'] = options.language;
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

  let response: Response;

  try {
    response = await fetch(`${baseUrl}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
    });
  } catch {
    throw new ApiError(0, 'offline', 'You appear to be offline.');
  }

  if (response.status === 204) return undefined as T;

  const payload: unknown = await response.json().catch(() => undefined);

  if (!response.ok) {
    const error = decodeError(response.status, payload);

    // A 401 is terminal for the session: the spec rotates tokens on refresh and
    // rejects an expired one outright, so holding on to it only produces more
    // 401s.
    if (error.isAuthFailure) await clearToken();

    throw error;
  }

  return payload as T;
}

/**
 * A GET that survives a tunnel.
 *
 * Reads are cached under their path and served from cache when the network
 * fails - with the timestamp, so a screen can say "as of 09:41" rather than
 * quietly showing yesterday's balance as though it were today's. Writes are
 * never cached and never replayed from here; that is the outbox's job.
 */
export async function cachedGet<T>(path: string, ttlMs = 5 * 60 * 1000): Promise<{
  data: T;
  stale: boolean;
  fetchedAt: number;
}> {
  const cached = await readCache<T>(path);
  const fresh = cached !== null && Date.now() - cached.fetchedAt < ttlMs;

  if (fresh) return { data: cached.data, stale: false, fetchedAt: cached.fetchedAt };

  try {
    const data = await request<T>(path);
    const fetchedAt = Date.now();
    await writeCache(path, data, fetchedAt);

    return { data, stale: false, fetchedAt };
  } catch (error) {
    if (cached && error instanceof ApiError && error.code === 'offline') {
      return { data: cached.data, stale: true, fetchedAt: cached.fetchedAt };
    }

    throw error;
  }
}
