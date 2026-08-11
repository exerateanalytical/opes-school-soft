/**
 * The wire shapes, transcribed from docs/api/openapi.yaml and the controllers
 * in app/Modules/Guardians/Http/Api.
 *
 * Money is ALWAYS minor units plus a currency code, never a float - the spec
 * says so and the server asserts it. `Money` exists so a screen cannot
 * accidentally treat `125000` as 125 thousand francs and render "125,000.00".
 */

export type Money = { amount: number; currency: string };

/** GuardianCapability values. The app hides a tile whose code is absent. */
export type Capability =
  | 'child.identity.view'
  | 'child.profile_detail.view'
  | 'child.medical_emergency.view'
  | 'child.medical_full.view'
  | 'results.report_card.view'
  | 'results.report_card.download'
  | 'results.marks_published.view'
  | 'results.rank.view'
  | 'results.promotion.view'
  | 'results.attendance_summary.view'
  | 'results.attendance_detail.view'
  | 'fees.invoices.view'
  | 'fees.statement.view'
  | 'fees.receipts.view'
  | 'fees.payments_own.view'
  | 'fees.payment.initiate'
  | 'discipline.list.view'
  | 'discipline.narrative.view'
  | 'discipline.sanction.acknowledge'
  | 'documents.school_issued.view'
  | 'documents.guardian_supplied.view'
  | 'school.timetable.view'
  | 'meeting.request'
  | 'guardian.own_contact.edit'
  | 'child.guardians.list';

export type Guardian = {
  id: number;
  display_name: string;
  first_name: string;
  last_name: string;
  phone: string | null;
  email: string | null;
  language: 'en' | 'fr';
  preferred_contact_method?: string;
};

export type Child = {
  id: number;
  matricule: string;
  first_name: string;
  last_name: string;
  display_name: string;
  class: string | null;
  status: string;
  has_photo: boolean;
  capabilities: Capability[];
  detail?: Record<string, unknown> | null;
};

export type MeResponse = {
  guardian: Guardian;
  capabilities_global: Capability[];
  as_of: string;
};

export type DashboardResponse = {
  children: Child[];
  children_count: number;
  capabilities_global: Capability[];
  as_of: string;
};

export type FeesResponse = {
  currency: string;
  has_enrollment: boolean;
  totals: { billed: number; paid: number; outstanding: number; next_due_on: string | null };
  structure: { fee_item: string; fee_item_fr: string | null; category_code: string | null; amount: number }[];
  invoices: { id: number; number: string | null; issued_on: string; due_on: string; total: number }[];
  installments: {
    id: number;
    invoice_id: number;
    sequence_no: number;
    label: string;
    label_fr: string | null;
    amount: number;
    due_on: string;
    status: 'overdue' | 'due_soon' | 'scheduled';
  }[];
  statement: {
    date: string;
    reference: string;
    description: string;
    debit: number;
    credit: number;
    balance: number;
  }[];
};

export type PaymentRow = {
  id: number;
  student_id: number;
  receipt_no: string;
  paid_on: string;
  amount: number;
  currency: string;
  payment_method: string;
  clearing_state: string;
  is_own: boolean;
  can_download_receipt: boolean;
};

export type ResultsResponse = {
  can_download: boolean;
  periods: {
    snapshot_id: number;
    period: { id: number; name: string; name_fr: string | null };
    generation: number;
    issued_at: string | null;
    payload: Record<string, unknown>;
    promotion?: Record<string, unknown>;
  }[];
};

export type AttendanceResponse = {
  scope: 'summary' | 'detail';
  summaries: Record<string, unknown>[];
  records: Record<string, unknown>[];
};

export type DisciplineResponse = {
  can_read_narrative: boolean;
  can_acknowledge: boolean;
  cases: {
    id: number;
    occurred_on: string;
    status: string;
    is_positive: boolean;
    category: { name: string; name_fr: string | null };
    description?: string | null;
    resolution_note?: string | null;
    sanctions: {
      id: number;
      type: string;
      starts_on: string | null;
      ends_on: string | null;
      acknowledged_at: string | null;
    }[];
  }[];
};

export type TimetableResponse = {
  slots: {
    day_of_week: number;
    period_name: string;
    starts_at: string;
    ends_at: string;
    subject_name: string | null;
    room_name: string | null;
  }[];
};

export type DocumentsResponse = {
  can_view_school_issued: boolean;
  can_view_guardian_supplied: boolean;
  school_issued: {
    id: number;
    serial: string | null;
    issued_at: string;
    language: string;
    verification_code: string | null;
    verify_url: string | null;
    has_bytes: false;
  }[];
  guardian_supplied: {
    id: number;
    title: string;
    issued_on: string | null;
    expires_on: string | null;
    verification_status: string;
    mime: string;
    size_bytes: number;
    has_bytes: true;
  }[];
};

export type NotificationRow = {
  id: number;
  kind: string;
  title: string;
  body: string | null;
  deep_link: string | null;
  read_at: string | null;
  created_at: string;
};

export type AnnouncementRow = {
  id: number;
  title: string;
  body: string | null;
  published_at: string | null;
  is_read: boolean;
};

export type ThreadRow = {
  id: number;
  title: string;
  kind: string;
  last_message_at: string | null;
  unread_count: number;
  is_archived: boolean;
};

export type MessageRow = {
  id: number;
  sender_id: number;
  sender_name: string | null;
  body: string;
  is_system: boolean;
  sent_at: string;
};

export type SearchHit = {
  type: 'child' | 'invoice' | 'receipt' | 'document' | 'discipline' | 'announcement';
  id: number;
  student_id: number | null;
  title: string;
  subtitle: string | null;
  deep_link: string;
};

export type Envelope<T> = { data: T; meta?: Record<string, unknown> };
