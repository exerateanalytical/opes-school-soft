import AsyncStorage from '@react-native-async-storage/async-storage';

import { ApiError } from '@/api/client';

/**
 * Writes made while offline, replayed when the network returns.
 *
 * The whole design rests on the server's `Idempotency-Key` contract (spec §5):
 * each queued write is stamped with a key WHEN IT IS QUEUED, not when it is
 * sent. So a replay that succeeded but whose response was lost to a dying
 * connection returns the ORIGINAL response on the retry instead of posting a
 * second message, booking a second meeting or signing a sanction twice.
 *
 * Generating the key at send time would defeat the entire mechanism, which is
 * why it is not a parameter of `flush()`.
 *
 * A 4xx other than 409 is permanent - the server has judged the request, and
 * retrying it forever would leave a parent with a queue that never drains and
 * no way to know why. Those are dropped and surfaced.
 */

const KEY = 'opes.outbox';

export type QueuedWrite = {
  id: string;
  idempotencyKey: string;
  label: string;
  queuedAt: number;
  path: string;
  method: 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  attempts: number;
};

function newKey(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
}

export async function readOutbox(): Promise<QueuedWrite[]> {
  const raw = await AsyncStorage.getItem(KEY);

  if (!raw) return [];

  try {
    return JSON.parse(raw) as QueuedWrite[];
  } catch {
    await AsyncStorage.removeItem(KEY);

    return [];
  }
}

async function save(items: QueuedWrite[]): Promise<void> {
  await AsyncStorage.setItem(KEY, JSON.stringify(items));
}

export async function enqueue(
  entry: Omit<QueuedWrite, 'id' | 'idempotencyKey' | 'queuedAt' | 'attempts'>,
): Promise<QueuedWrite> {
  const queued: QueuedWrite = {
    ...entry,
    id: newKey(),
    // Stamped HERE. See the docblock.
    idempotencyKey: newKey(),
    queuedAt: Date.now(),
    attempts: 0,
  };

  await save([...(await readOutbox()), queued]);

  return queued;
}

export type FlushResult = { sent: number; failed: { entry: QueuedWrite; error: ApiError }[] };

export async function flush(
  send: (entry: QueuedWrite) => Promise<void>,
): Promise<FlushResult> {
  const items = await readOutbox();
  const remaining: QueuedWrite[] = [];
  const failed: { entry: QueuedWrite; error: ApiError }[] = [];
  let sent = 0;

  for (const entry of items) {
    try {
      await send(entry);
      sent++;
    } catch (error) {
      if (!(error instanceof ApiError)) {
        remaining.push({ ...entry, attempts: entry.attempts + 1 });
        continue;
      }

      // Still offline - keep the whole rest of the queue in order. Sending
      // later items now would reorder a parent's messages.
      if (error.code === 'offline') {
        remaining.push({ ...entry, attempts: entry.attempts + 1 });
        continue;
      }

      // 409 means this key was replayed with a different body - a bug on our
      // side, not a transient failure. Drop it and say so.
      if (error.status >= 400 && error.status < 500) {
        failed.push({ entry, error });
        continue;
      }

      remaining.push({ ...entry, attempts: entry.attempts + 1 });
    }
  }

  await save(remaining);

  return { sent, failed };
}

export async function clearOutbox(): Promise<void> {
  await AsyncStorage.removeItem(KEY);
}
