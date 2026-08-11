import * as SecureStore from 'expo-secure-store';

/**
 * The device token lives in the OS keystore, never in AsyncStorage.
 *
 * It is a bearer credential with a 30-day life that grants read AND write
 * access to a family's records; AsyncStorage is plain, world-readable-on-a-
 * rooted-device JSON. The `device_id` is generated once and kept beside it,
 * because the server names the token `mobile:{platform}:{device_id}` and uses
 * that name to revoke this device's PREVIOUS token on re-authentication - a
 * device that forgot its id would leave an orphan token alive for 30 days.
 */

const TOKEN_KEY = 'opes.guardian.token';
const DEVICE_KEY = 'opes.guardian.device_id';

export type StoredToken = { value: string; expiresAt: string };

export async function readToken(): Promise<StoredToken | null> {
  const raw = await SecureStore.getItemAsync(TOKEN_KEY);

  if (!raw) return null;

  try {
    return JSON.parse(raw) as StoredToken;
  } catch {
    // A corrupt entry is not a credential. Drop it rather than sending
    // rubbish in an Authorization header on every request from now on.
    await SecureStore.deleteItemAsync(TOKEN_KEY);

    return null;
  }
}

export async function writeToken(token: StoredToken): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, JSON.stringify(token));
}

export async function clearToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

/** Stable per install. Regenerated only if the keystore entry is lost. */
export async function deviceId(): Promise<string> {
  const existing = await SecureStore.getItemAsync(DEVICE_KEY);

  if (existing) return existing;

  const generated = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  await SecureStore.setItemAsync(DEVICE_KEY, generated);

  return generated;
}
