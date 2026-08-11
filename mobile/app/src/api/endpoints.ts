import { request, cachedGet } from './client';
import type {
  AnnouncementRow,
  AttendanceResponse,
  Child,
  DashboardResponse,
  DisciplineResponse,
  DocumentsResponse,
  Envelope,
  FeesResponse,
  MeResponse,
  MessageRow,
  NotificationRow,
  PaymentRow,
  ResultsResponse,
  SearchHit,
  ThreadRow,
  TimetableResponse,
} from './types';

/**
 * One function per documented operation, named after it. Nothing here builds a
 * URL by hand at a call site, so a route rename is a single edit and a
 * type error rather than a 404 discovered by a parent.
 *
 * The TTLs mirror spec §4.2's caching note: the things that change slowly
 * (children, timetable, announcements) hold for longer; medical and downloads
 * are never cached at all, because `Cache-Control: private, no-store` on the
 * server would be pointless if the client wrote them to disk anyway.
 */

const MINUTE = 60 * 1000;

export const auth = {
  token: (body: {
    identifier: string;
    password: string;
    device_name: string;
    device_id: string;
    platform: string;
  }) =>
    request<Envelope<{ token: string; expires_at: string; abilities: string[]; guardian: unknown }>>(
      '/auth/token',
      { method: 'POST', body },
    ),

  refresh: () =>
    request<Envelope<{ token: string; expires_at: string }>>('/auth/refresh', { method: 'POST' }),

  logout: () => request<unknown>('/auth/logout', { method: 'POST' }),
  logoutAll: () => request<unknown>('/auth/logout-all', { method: 'POST' }),
  devices: () => request<Envelope<unknown[]>>('/auth/devices'),
  forgetDevice: (id: number) => request<unknown>(`/auth/devices/${id}`, { method: 'DELETE' }),
};

export const me = {
  show: () => cachedGet<Envelope<MeResponse>>('/me', 5 * MINUTE),
  children: () => cachedGet<Envelope<Child[]>>('/me/children', 10 * MINUTE),
  dashboard: () => cachedGet<Envelope<DashboardResponse>>('/me/dashboard', 2 * MINUTE),

  /** Medical is never written to disk - see §4.2. */
  medical: (student: number) =>
    request<Envelope<Record<string, unknown>>>(`/me/children/${student}/medical`),

  child: (student: number) => cachedGet<Envelope<Child>>(`/me/children/${student}`, 10 * MINUTE),
  otherGuardians: (student: number) =>
    cachedGet<Envelope<unknown[]>>(`/me/children/${student}/guardians`, 30 * MINUTE),

  results: (student: number) =>
    cachedGet<Envelope<ResultsResponse>>(`/me/children/${student}/results`, 10 * MINUTE),
  attendance: (student: number) =>
    cachedGet<Envelope<AttendanceResponse>>(`/me/children/${student}/attendance`, 10 * MINUTE),
  discipline: (student: number) =>
    cachedGet<Envelope<DisciplineResponse>>(`/me/children/${student}/discipline`, 10 * MINUTE),
  timetable: (student: number) =>
    cachedGet<Envelope<TimetableResponse>>(`/me/children/${student}/timetable`, 60 * MINUTE),

  fees: (student: number) =>
    cachedGet<Envelope<FeesResponse>>(`/me/children/${student}/fees`, 2 * MINUTE),
  invoice: (student: number, invoice: number) =>
    request<Envelope<Record<string, unknown>>>(`/me/children/${student}/invoices/${invoice}`),
  receipt: (student: number, payment: number) =>
    request<Envelope<Record<string, unknown>>>(`/me/children/${student}/receipts/${payment}`),
  payments: () => cachedGet<Envelope<PaymentRow[]>>('/me/payments', 2 * MINUTE),

  documents: (student: number) =>
    cachedGet<Envelope<DocumentsResponse>>(`/me/children/${student}/documents`, 10 * MINUTE),
  documentDownloadPath: (student: number, kind: 'school' | 'supplied', id: number) =>
    `/me/children/${student}/documents/${kind}/${id}/download`,

  announcements: () => cachedGet<Envelope<AnnouncementRow[]>>('/me/announcements', 10 * MINUTE),
  notifications: () => request<Envelope<NotificationRow[]>>('/me/notifications'),
  threads: () => cachedGet<Envelope<ThreadRow[]>>('/me/threads', MINUTE),
  messages: (thread: number) => request<Envelope<MessageRow[]>>(`/me/threads/${thread}/messages`),

  search: (q: string) =>
    request<Envelope<{ query: string; results: SearchHit[] }>>(
      `/me/search?q=${encodeURIComponent(q)}`,
    ),
};

export const writes = {
  readNotification: (id: number) =>
    request<unknown>(`/me/notifications/${id}/read`, { method: 'POST' }),
  readAllNotifications: () => request<unknown>('/me/notifications/read-all', { method: 'POST' }),

  sendMessage: (thread: number, body: string, idempotencyKey: string) =>
    request<Envelope<{ id: number }>>(`/me/threads/${thread}/messages`, {
      method: 'POST',
      body: { body },
      idempotencyKey,
    }),

  updateProfile: (body: Record<string, unknown>) =>
    request<Envelope<Record<string, unknown>>>('/me/profile', { method: 'PATCH', body }),

  registerPush: (body: { endpoint: string; p256dh: string; auth: string; platform: string }) =>
    request<Envelope<{ id: number }>>('/me/devices/push', { method: 'POST', body }),
  unregisterPush: (endpoint: string) =>
    request<unknown>('/me/devices/push', { method: 'DELETE', body: { endpoint } }),

  requestMeeting: (
    student: number,
    body: { preferred_at: string; meeting_type?: string; agenda?: string },
    idempotencyKey: string,
  ) =>
    request<Envelope<{ id: number }>>(`/me/children/${student}/meetings`, {
      method: 'POST',
      body,
      idempotencyKey,
    }),

  acknowledgeSanction: (student: number, sanction: number, idempotencyKey: string) =>
    request<Envelope<{ acknowledged: boolean }>>(
      `/me/children/${student}/sanctions/${sanction}/ack`,
      { method: 'POST', idempotencyKey },
    ),

  /**
   * Returns 501 until a gateway exists (spec §1 non-goals). Wired anyway so
   * the payment flow ships against the real contract and shows the real
   * message, rather than a mock that would be torn out later.
   */
  initiatePayment: (student: number, idempotencyKey: string) =>
    request<unknown>(`/me/children/${student}/payments`, { method: 'POST', idempotencyKey }),
};
