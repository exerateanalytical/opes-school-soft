import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * The read cache that makes the app usable on a bad connection.
 *
 * Two rules it must never break:
 *
 *   1. NOTHING medical, and no download bytes, are written here. The server
 *      sends `Cache-Control: private, no-store` on those, and honouring that
 *      header on the server while writing the body to disk on the client
 *      would make the header theatre.
 *   2. The cache is cleared on sign-out. A shared family phone is the normal
 *      case, not the edge case, and a second parent must not find the first
 *      one's children in a stale cache.
 */

const PREFIX = 'opes.cache:';
const INDEX = 'opes.cache.index';

type Entry<T> = { data: T; fetchedAt: number };

export async function readCache<T>(path: string): Promise<Entry<T> | null> {
  const raw = await AsyncStorage.getItem(PREFIX + path);

  if (!raw) return null;

  try {
    return JSON.parse(raw) as Entry<T>;
  } catch {
    await AsyncStorage.removeItem(PREFIX + path);

    return null;
  }
}

export async function writeCache<T>(path: string, data: T, fetchedAt: number): Promise<void> {
  await AsyncStorage.setItem(PREFIX + path, JSON.stringify({ data, fetchedAt } satisfies Entry<T>));

  // An index, so clearing does not depend on getAllKeys() - which is slow on
  // Android once a family has browsed a term's worth of screens.
  const raw = await AsyncStorage.getItem(INDEX);
  const keys: string[] = raw ? (JSON.parse(raw) as string[]) : [];

  if (!keys.includes(path)) {
    keys.push(path);
    await AsyncStorage.setItem(INDEX, JSON.stringify(keys));
  }
}

export async function clearCache(): Promise<void> {
  const raw = await AsyncStorage.getItem(INDEX);
  const keys: string[] = raw ? (JSON.parse(raw) as string[]) : [];

  await AsyncStorage.multiRemove([...keys.map((key) => PREFIX + key), INDEX]);
}
