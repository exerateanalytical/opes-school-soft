import { Platform } from 'react-native';
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
 *
 * WEB is the exception, and it is the platform's, not a choice made here:
 * expo-secure-store has no web backend at all - every call throws
 * `getValueWithKeyAsync is not a function` - so on web the only place to put
 * this is localStorage. That is genuinely weaker storage, which is why web is
 * a development and design-review target (the parity harness in
 * tools/design-parity/mobile renders the app there) and not a shipping one.
 * The shape of the module is identical on both, so no caller learns about it.
 */

const TOKEN_KEY = 'opes.guardian.token';
const DEVICE_KEY = 'opes.guardian.device_id';

const store = {
  get: (key: string): Promise<string | null> =>
    Platform.OS === 'web'
      ? Promise.resolve(globalThis.localStorage?.getItem(key) ?? null)
      : SecureStore.getItemAsync(key),

  set: (key: string, value: string): Promise<void> =>
    Platform.OS === 'web'
      ? Promise.resolve(globalThis.localStorage?.setItem(key, value))
      : SecureStore.setItemAsync(key, value),

  remove: (key: string): Promise<void> =>
    Platform.OS === 'web'
      ? Promise.resolve(globalThis.localStorage?.removeItem(key))
      : SecureStore.deleteItemAsync(key),
};

export type StoredToken = { value: string; expiresAt: string };

export async function readToken(): Promise<StoredToken | null> {
  const raw = await store.get(TOKEN_KEY);

  if (!raw) return null;

  try {
    return JSON.parse(raw) as StoredToken;
  } catch {
    // A corrupt entry is not a credential. Drop it rather than sending
    // rubbish in an Authorization header on every request from now on.
    await store.remove(TOKEN_KEY);

    return null;
  }
}

export async function writeToken(token: StoredToken): Promise<void> {
  await store.set(TOKEN_KEY, JSON.stringify(token));
}

export async function clearToken(): Promise<void> {
  await store.remove(TOKEN_KEY);
}

/** Stable per install. Regenerated only if the keystore entry is lost. */
export async function deviceId(): Promise<string> {
  const existing = await store.get(DEVICE_KEY);

  if (existing) return existing;

  const generated = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  await store.set(DEVICE_KEY, generated);

  return generated;
}
