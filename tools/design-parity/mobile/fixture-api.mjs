/*
 * A stand-in guardian API for the parity harness. NOT a mock of behaviour -
 * a fixture of CONTENT.
 *
 * Why this exists rather than a fixture mode inside the app: a screenshot of a
 * screen showing a spinner proves nothing about spacing, and a screen showing
 * invented data proves nothing about the design. The reference PNGs all show
 * Emmanuel Ngo, Grade 6B, HBC24567, Heritage Bilingual College and a specific
 * set of amounts - so the harness serves exactly those, and the built screen
 * can be compared against the design line for line.
 *
 * Keeping it out of the app matters: the app runs its REAL client, its real
 * envelope decoding, its real session flow and its real error taxonomy against
 * this. Nothing about the shipped bundle changes for the harness, so the thing
 * being photographed is the thing that ships.
 *
 * Shapes are transcribed from mobile/app/src/api/types.ts. Money is minor
 * units + currency; XAF has no centimes, so 850000 is 850,000 FCFA.
 *
 * Endpoints the server does not implement yet (documents, announcements,
 * notifications, threads, search, payments, invoices, receipts - Slices D-F)
 * are served here too, because the screens for them exist and must be
 * photographed. When those slices land, this file is what their payloads are
 * checked against.
 */

import { createServer } from 'node:http';

const PORT = Number(process.argv[2] ?? 8000);
const XAF = 'XAF';
const AS_OF = '2026-05-15';

const CAPS_FULL = [
  'child.identity.view',
  'child.profile_detail.view',
  'child.medical_emergency.view',
  'child.medical_full.view',
  'results.report_card.view',
  'results.report_card.download',
  'results.marks_published.view',
  'results.rank.view',
  'results.promotion.view',
  'results.attendance_summary.view',
  'results.attendance_detail.view',
  'fees.invoices.view',
  'fees.statement.view',
  'fees.receipts.view',
  'fees.payments_own.view',
  'fees.payment.initiate',
  'discipline.list.view',
  'discipline.narrative.view',
  'discipline.sanction.acknowledge',
  'documents.school_issued.view',
  'documents.guardian_supplied.view',
  'school.timetable.view',
  'meeting.request',
  'guardian.own_contact.edit',
  'child.guardians.list',
];

const CHILDREN = [
  {
    id: 1201,
    matricule: 'HBC24567',
    first_name: 'Emmanuel',
    last_name: 'Ngo',
    display_name: 'Emmanuel Ngo',
    class: 'Grade 6B',
    status: 'active',
    has_photo: true,
    capabilities: CAPS_FULL,
    detail: {
      date_of_birth: '2013-03-12',
      age: 11,
      gender: 'Male',
      admission_no: 'ADM/2021/0456',
      admitted_on: '2021-09-10',
      nationality: 'Cameroonian',
      blood_group: 'O+',
      house: 'Green House',
      school: 'Heritage Bilingual College',
      class_teacher: 'Mrs. A. Nkeng',
      overall_average: '84%',
      class_rank: '5 / 32',
      attendance_rate: '95%',
      behaviour: 'Excellent',
    },
  },
  {
    id: 1202,
    matricule: 'HBC35678',
    first_name: 'Daniela',
    last_name: 'Ngo',
    display_name: 'Daniela Ngo',
    class: 'Grade 3A',
    status: 'active',
    has_photo: true,
    capabilities: CAPS_FULL,
    detail: { overall_average: '78%', attendance_rate: '92%', behaviour: 'Very Good' },
  },
  {
    id: 1203,
    matricule: 'HBC45789',
    first_name: 'Joshua',
    last_name: 'Ngo',
    display_name: 'Joshua Ngo',
    class: 'Grade 1B',
    status: 'active',
    has_photo: true,
    capabilities: CAPS_FULL,
    detail: { overall_average: '88%', attendance_rate: '98%', behaviour: 'Excellent' },
  },
];

const GUARDIAN = {
  id: 42,
  display_name: 'Mrs. Ngo Beth',
  first_name: 'Beth',
  last_name: 'Ngo',
  phone: '+237 677 12 34 56',
  email: 'beth.ngo@example.cm',
  language: 'en',
  preferred_contact_method: 'whatsapp',
};

const FEES = {
  currency: XAF,
  has_enrollment: true,
  totals: { billed: 850000, paid: 510000, outstanding: 340000, next_due_on: '2026-05-15' },
  structure: [
    { fee_item: 'Tuition Fee', fee_item_fr: 'Frais de scolarité', category_code: 'TUI', amount: 250000 },
    { fee_item: 'Development Fee', fee_item_fr: 'Frais de développement', category_code: 'DEV', amount: 50000 },
    { fee_item: 'Examination Fee', fee_item_fr: "Frais d'examen", category_code: 'EXA', amount: 25000 },
    { fee_item: 'Transport Fees', fee_item_fr: 'Frais de transport', category_code: 'TRA', amount: 140000 },
    { fee_item: 'Library Fee', fee_item_fr: 'Frais de bibliothèque', category_code: 'LIB', amount: 30000 },
    { fee_item: 'Uniform Fees', fee_item_fr: 'Frais d’uniforme', category_code: 'UNI', amount: 80000 },
  ],
  invoices: [
    { id: 9001, number: 'INV-2024-067', issued_on: '2026-04-01', due_on: '2026-05-15', total: 200000 },
    { id: 9002, number: 'INV-2024-068', issued_on: '2026-04-01', due_on: '2026-05-15', total: 140000 },
  ],
  installments: [
    { id: 1, invoice_id: 9001, sequence_no: 1, label: 'School Fees – Term 3', label_fr: 'Scolarité – 3e trimestre', amount: 200000, due_on: '2026-05-15', status: 'overdue' },
    { id: 2, invoice_id: 9002, sequence_no: 1, label: 'Transport Fees – Term 3', label_fr: 'Transport – 3e trimestre', amount: 140000, due_on: '2026-05-15', status: 'overdue' },
    { id: 3, invoice_id: 9001, sequence_no: 2, label: 'School Fees – Term 4', label_fr: 'Scolarité – 4e trimestre', amount: 200000, due_on: '2026-08-15', status: 'scheduled' },
  ],
  statement: [
    { date: '2026-04-10', reference: 'INV-2024-067', description: 'School Fees – Term 2', debit: 200000, credit: 0, balance: 200000 },
    { date: '2026-04-10', reference: 'RCP-2024-041', description: 'MTN Mobile Money', debit: 0, credit: 200000, balance: 0 },
    { date: '2026-05-01', reference: 'INV-2024-067', description: 'School Fees – Term 3', debit: 200000, credit: 0, balance: 200000 },
    { date: '2026-05-01', reference: 'INV-2024-068', description: 'Transport Fees – Term 3', debit: 140000, credit: 0, balance: 340000 },
  ],
};

const PAYMENTS = [
  { id: 5001, student_id: 1201, receipt_no: 'RCP-2024-041', paid_on: '2026-04-10', amount: 200000, currency: XAF, payment_method: 'MTN Mobile Money', clearing_state: 'cleared', is_own: true, can_download_receipt: true },
  { id: 5002, student_id: 1201, receipt_no: 'RCP-2024-042', paid_on: '2026-04-10', amount: 140000, currency: XAF, payment_method: 'MTN Mobile Money', clearing_state: 'cleared', is_own: true, can_download_receipt: true },
  { id: 5003, student_id: 1201, receipt_no: 'RCP-2023-119', paid_on: '2025-12-15', amount: 200000, currency: XAF, payment_method: 'Bank Transfer', clearing_state: 'cleared', is_own: true, can_download_receipt: true },
  { id: 5004, student_id: 1201, receipt_no: 'RCP-2023-120', paid_on: '2025-12-15', amount: 120000, currency: XAF, payment_method: 'Bank Transfer', clearing_state: 'cleared', is_own: false, can_download_receipt: false },
];

const RESULTS = {
  can_download: true,
  periods: [
    {
      snapshot_id: 7001,
      period: { id: 3, name: 'Term 3 (2023/2024)', name_fr: '3e trimestre (2023/2024)' },
      generation: 1,
      issued_at: '2026-05-10T09:00:00Z',
      payload: {
        overall_average: '84',
        overall_average_out_of: '100',
        grade: 'A-',
        rank: '5',
        class_size: 32,
        class_mean: '72',
        subjects_passed: '8 / 9',
        progress: '+12%',
        subjects: [
          { name: 'Mathematics', teacher: 'Mrs. A. Njomo', grade: 'A-', score: '85', trend: '+8%' },
          { name: 'Science', teacher: 'Mr. P. Ekane', grade: 'B+', score: '78', trend: '+5%' },
          { name: 'English Language', teacher: 'Mrs. M. Takam', grade: 'A', score: '90', trend: '+12%' },
          { name: 'Social Studies', teacher: 'Mr. J. Mbarga', grade: 'B', score: '72', trend: '-3%' },
          { name: 'Physical Education', teacher: 'Mr. L. Feudjio', grade: 'A', score: '88', trend: '+7%' },
        ],
        assessments: { excellent: 12, good: 10, average: 4, needs_work: 2, total: 28 },
        trend: [{ period: 'Term 1', value: 72 }, { period: 'Term 2', value: 72 }, { period: 'Term 3', value: 84 }],
        teacher_comment:
          'Emmanuel is performing well above average. Keep encouraging consistency, especially in Social Studies.',
      },
      promotion: { outcome: 'promoted', annual_average: '15.31' },
    },
    {
      snapshot_id: 7000,
      period: { id: 2, name: 'Term 2 (2023/2024)', name_fr: '2e trimestre (2023/2024)' },
      generation: 1,
      issued_at: '2026-02-10T09:00:00Z',
      payload: { overall_average: '72', grade: 'B', rank: '7', class_size: 32, subjects: [] },
    },
  ],
};

const ATTENDANCE = {
  scope: 'detail',
  summaries: [
    {
      period_name: 'Term 3 (2023/2024)',
      period_name_fr: '3e trimestre',
      sessions_expected: 100,
      sessions_present: 86,
      sessions_absent: 12,
      sessions_excused: 4,
      sessions_late: 2,
      retards: 2,
      hours_absent_justified: '8.00',
      hours_absent_unjustified: '4.00',
      computed_at: '2026-05-15T06:00:00Z',
    },
  ],
  records: [
    { session_date: '2026-05-16', session: 'full_day', status: 'present', is_justified: 0, justification_type: null, minutes_late: null, remark: null },
    { session_date: '2026-05-15', session: 'full_day', status: 'present', is_justified: 0, justification_type: null, minutes_late: null, remark: null },
    { session_date: '2026-05-14', session: 'full_day', status: 'present', is_justified: 0, justification_type: null, minutes_late: null, remark: null },
    { session_date: '2026-05-13', session: 'full_day', status: 'present', is_justified: 0, justification_type: null, minutes_late: null, remark: null },
    { session_date: '2026-05-09', session: 'full_day', status: 'absent', is_justified: 1, justification_type: 'medical', minutes_late: null, remark: 'Medical' },
    { session_date: '2026-05-17', session: 'morning', status: 'late', is_justified: 0, justification_type: null, minutes_late: 15, remark: null },
  ],
};

const DISCIPLINE = {
  can_read_narrative: true,
  can_acknowledge: true,
  cases: [
    { id: 301, occurred_on: '2026-05-16', status: 'resolved', is_positive: true, category: { name: 'Helpful to a Classmate', name_fr: 'Serviable' }, description: 'Helped a classmate with Mathematics assignment.', resolution_note: null, sanctions: [] },
    { id: 302, occurred_on: '2026-05-10', status: 'resolved', is_positive: true, category: { name: 'Active Participation', name_fr: 'Participation active' }, description: 'Participated actively in class discussion.', resolution_note: null, sanctions: [] },
    { id: 303, occurred_on: '2026-05-06', status: 'resolved', is_positive: false, category: { name: 'Talked in Class', name_fr: 'Bavardage' }, description: 'Talked during lesson after reminder.', resolution_note: 'Verbal warning given.', sanctions: [{ id: 91, type: 'warning', starts_on: '2026-05-06', ends_on: null, acknowledged_at: null }] },
    { id: 304, occurred_on: '2026-04-30', status: 'resolved', is_positive: false, category: { name: 'Late to Class', name_fr: 'Retard' }, description: 'Arrived 10 minutes late.', resolution_note: null, sanctions: [] },
  ],
};

const TIMETABLE = {
  slots: [1, 2, 3, 4, 5].flatMap((day) => [
    { day_of_week: day, period_name: 'Period 1', starts_at: '07:30', ends_at: '08:25', subject_name: 'Mathematics', room_name: 'Room 12' },
    { day_of_week: day, period_name: 'Period 2', starts_at: '08:25', ends_at: '09:20', subject_name: 'English Language', room_name: 'Room 12' },
    { day_of_week: day, period_name: 'Period 3', starts_at: '09:35', ends_at: '10:30', subject_name: 'Science', room_name: 'Lab 2' },
    { day_of_week: day, period_name: 'Period 4', starts_at: '10:30', ends_at: '11:25', subject_name: 'Social Studies', room_name: 'Room 12' },
  ]),
};

const DOCUMENTS = {
  can_view_school_issued: true,
  can_view_guardian_supplied: true,
  school_issued: [
    { id: 8001, serial: 'RC-2024-2025-003', issued_at: '2026-05-10T09:00:00Z', language: 'en', verification_code: 'RC-2026-0700158', verify_url: 'https://opesware.com/verify/RC-2026-0700158', has_bytes: false },
    { id: 8002, serial: 'RCP-2026-0700158', issued_at: '2026-04-10T10:24:00Z', language: 'en', verification_code: 'RCP-2026-0700158', verify_url: 'https://opesware.com/verify/RCP-2026-0700158', has_bytes: false },
  ],
  guardian_supplied: [
    { id: 8101, title: 'Birth Certificate', issued_on: '2013-04-02', expires_on: null, verification_status: 'verified', mime: 'application/pdf', size_bytes: 1258291, has_bytes: true },
    { id: 8102, title: 'Immunization Certificate', issued_on: '2023-08-02', expires_on: null, verification_status: 'unverified', mime: 'application/pdf', size_bytes: 696320, has_bytes: true },
  ],
};

const MEDICAL = {
  scope: 'full',
  health_status: 'Good',
  last_updated: '2026-05-12',
  updated_by: 'School Nurse',
  blood_group: 'O+',
  genotype: 'AA',
  allergies: [],
  chronic_conditions: [],
  immunizations: [
    { vaccine: 'BCG', disease: 'Tuberculosis (TB)', given_on: '2014-01-12', status: 'completed', next_due: null },
    { vaccine: 'DPT 1', disease: 'Diphtheria, Pertussis, Tetanus', given_on: '2014-03-12', status: 'completed', next_due: null },
    { vaccine: 'DPT 2', disease: 'Diphtheria, Pertussis, Tetanus', given_on: '2014-05-12', status: 'completed', next_due: null },
    { vaccine: 'Hepatitis B', disease: 'Hepatitis B', given_on: '2014-03-12', status: 'completed', next_due: null },
    { vaccine: 'MMR', disease: 'Measles, Mumps, Rubella', given_on: '2014-06-12', status: 'completed', next_due: null },
  ],
  visits: [
    { id: 401, title: 'General Check-up', description: 'Routine health examination', occurred_on: '2026-05-12', time: '10:30', location: 'School Clinic', status: 'completed', clinician: 'Dr. A. Nguimatsia' },
    { id: 402, title: 'Fever', description: 'Mild fever and headache', occurred_on: '2026-02-28', time: '11:15', location: 'School Clinic', status: 'completed', clinician: 'Dr. M. Ekono' },
    { id: 403, title: 'Stomach Pain', description: 'Abdominal pain and nausea', occurred_on: '2025-11-15', time: '09:40', location: 'School Clinic', status: 'completed', clinician: 'Dr. J. Mbarga' },
  ],
  documents: [
    { id: 501, title: 'General Check-up Report', kind: 'Medical Report', issued_on: '2026-05-12', mime: 'application/pdf', size_bytes: 1258291, source: 'School Clinic' },
    { id: 502, title: 'Blood Test Results', kind: 'Lab Result', issued_on: '2026-02-28', mime: 'application/pdf', size_bytes: 774144, source: 'School Clinic' },
  ],
  health_id: { number: 'OHID-23-000456', status: 'active', issued_on: '2023-09-01' },
};

const OTHER_GUARDIANS = [
  { id: 43, display_name: 'Mr. Jean Ngo', relationship: 'father', is_primary: true },
  { id: 42, display_name: 'Mrs. Marie Ngo', relationship: 'mother', is_primary: false },
];

const ANNOUNCEMENTS = [
  { id: 601, title: 'Mid-Term Break Notice', body: 'The school will be closed for Mid-Term Break from 20 May to 24 May 2026. Classes resume Monday, 27 May 2026.', published_at: '2026-05-12T08:00:00Z', is_read: false },
  { id: 602, title: 'Cultural Day 2026', body: 'Our annual Cultural Day will hold on Friday, 31 May 2026. Students are encouraged to participate.', published_at: '2026-05-10T08:00:00Z', is_read: true },
  { id: 603, title: 'End of Term 2 Examinations', body: 'Term 2 Examinations will take place from 03 June to 07 June 2026.', published_at: '2026-05-08T08:00:00Z', is_read: true },
  { id: 604, title: 'School Bus Schedule Update', body: 'The afternoon bus schedule has been updated effective Monday, 13 May 2026.', published_at: '2026-05-06T08:00:00Z', is_read: true },
  { id: 605, title: 'PTA Meeting Reminder', body: 'The next PTA meeting will hold this Friday, 17 May 2026 at 4:00 PM in the School Library.', published_at: '2026-05-05T08:00:00Z', is_read: true },
];

const NOTIFICATIONS = [
  { id: 701, kind: 'academics', title: 'Term 3 Report Cards Released', body: "Emmanuel's Term 3 report card is now available.", deep_link: 'opes://children/1201/results', read_at: null, created_at: '2026-05-15T08:30:00Z' },
  { id: 702, kind: 'payments', title: 'Fee Payment Reminder', body: 'You have an outstanding balance of 125,000 FCFA for Emmanuel and Daniela.', deep_link: 'opes://children/1201/fees', read_at: null, created_at: '2026-05-15T07:45:00Z' },
  { id: 703, kind: 'announcement', title: 'School Excursion to Limbe', body: 'Permission slips and details are now available. Please respond by 15 May 2026.', deep_link: 'opes://announcements', read_at: null, created_at: '2026-05-15T07:20:00Z' },
  { id: 704, kind: 'discipline', title: 'Discipline Update: Emmanuel', body: "New comment from Class Teacher regarding Emmanuel's behavior.", deep_link: 'opes://children/1201/discipline', read_at: null, created_at: '2026-05-15T06:15:00Z' },
  { id: 705, kind: 'events', title: 'Science Fair 2026 – Reminder', body: 'Science Fair is scheduled for 15 May 2026 at the School Main Hall.', deep_link: 'opes://activities', read_at: '2026-05-14T18:00:00Z', created_at: '2026-05-14T18:00:00Z' },
];

const THREADS = [
  { id: 801, title: 'Mrs. A. Nkeng', kind: 'teacher', last_message_at: '2026-05-15T08:45:00Z', unread_count: 2, is_archived: false },
  { id: 802, title: 'School Administration', kind: 'admin', last_message_at: '2026-05-14T15:10:00Z', unread_count: 0, is_archived: false },
  { id: 803, title: 'Bursary Office', kind: 'finance', last_message_at: '2026-05-12T11:02:00Z', unread_count: 3, is_archived: false },
];

const MESSAGES = [
  { id: 9001, sender_id: 55, sender_name: 'Mrs. A. Nkeng', body: "Hello Mr. and Mrs. Ngo,\nI wanted to share Emmanuel's performance in the recent Mathematics quiz. He did very well and is showing great improvement!", is_system: false, sent_at: '2026-05-14T09:15:00Z' },
  { id: 9002, sender_id: 42, sender_name: 'Mrs. Ngo Beth', body: 'Good morning Mrs. Nkeng,\nThank you so much for the update! We are very proud of him. Please keep us informed.', is_system: false, sent_at: '2026-05-14T09:17:00Z' },
  { id: 9003, sender_id: 55, sender_name: 'Mrs. A. Nkeng', body: 'You’re welcome! Also, please find attached the Mid-Term report for your review.', is_system: false, sent_at: '2026-05-14T09:18:00Z' },
  { id: 9004, sender_id: 42, sender_name: 'Mrs. Ngo Beth', body: 'Thank you, we have received the report. We will go through it and get back to you if we have any questions.', is_system: false, sent_at: '2026-05-15T08:42:00Z' },
  { id: 9005, sender_id: 55, sender_name: 'Mrs. A. Nkeng', body: 'Thank you! Please don’t hesitate to reach out anytime.', is_system: false, sent_at: '2026-05-15T08:45:00Z' },
];

const SEARCH_HITS = [
  { type: 'child', id: 1201, student_id: 1201, title: 'Emmanuel Ngo', subtitle: 'Form 5A • Student ID: HBC00124', deep_link: 'opes://children/1201' },
  { type: 'document', id: 8001, student_id: 1201, title: 'Emmanuel Ngo – Term 3 Report Card', subtitle: 'Report Cards • 1.2 MB • Released 5 May 2026', deep_link: 'opes://children/1201/results' },
  { type: 'announcement', id: 604, student_id: null, title: 'Discipline Update: Emmanuel', subtitle: 'New comment from Class Teacher', deep_link: 'opes://announcements' },
  { type: 'invoice', id: 9001, student_id: 1201, title: 'INV-2024-067', subtitle: 'School Fees – Term 3 • 200,000 FCFA', deep_link: 'opes://children/1201/fees' },
];

const routes = [
  [/^\/auth\/token$/, () => ({ status: 201, body: { data: { token: 'parity|fixture-token', expires_at: '2026-09-10T21:00:00Z', abilities: ['portal.read', 'portal.write'], guardian: GUARDIAN } } })],
  [/^\/auth\/refresh$/, () => ({ status: 200, body: { data: { token: 'parity|fixture-token', expires_at: '2026-09-10T21:00:00Z' } } })],
  [/^\/auth\/logout(-all)?$/, () => ({ status: 200, body: { data: { revoked: true } } })],
  [/^\/auth\/devices$/, () => ({ status: 200, body: { data: [{ id: 1, platform: 'android', created_at: '2026-05-01T08:00:00Z', last_used_at: AS_OF + 'T08:00:00Z', expires_at: '2026-09-10T21:00:00Z', is_current: true }] } })],

  [/^\/me$/, () => ({ status: 200, body: { data: { guardian: GUARDIAN, capabilities_global: ['fees.payments_own.view', 'school.timetable.view', 'guardian.own_contact.edit'], as_of: AS_OF } } })],
  [/^\/me\/children$/, () => ({ status: 200, body: { data: CHILDREN } })],
  [/^\/me\/dashboard$/, () => ({ status: 200, body: { data: { children: CHILDREN, children_count: CHILDREN.length, capabilities_global: ['fees.payments_own.view', 'school.timetable.view'], as_of: AS_OF } } })],
  [/^\/me\/payments$/, () => ({ status: 200, body: { data: PAYMENTS } })],
  [/^\/me\/announcements$/, () => ({ status: 200, body: { data: ANNOUNCEMENTS } })],
  [/^\/me\/notifications$/, () => ({ status: 200, body: { data: NOTIFICATIONS } })],
  [/^\/me\/threads$/, () => ({ status: 200, body: { data: THREADS } })],
  [/^\/me\/threads\/\d+\/messages$/, () => ({ status: 200, body: { data: MESSAGES } })],
  [/^\/me\/search/, (path) => ({ status: 200, body: { data: { query: new URL('http://x' + path).searchParams.get('q') ?? '', results: SEARCH_HITS } } })],

  [/^\/me\/children\/(\d+)$/, (_p, m) => {
    const child = CHILDREN.find((c) => c.id === Number(m[1]));

    return child ? { status: 200, body: { data: child } } : { status: 404, body: { error: { code: 'not_found', message: 'Not found' } } };
  }],
  [/^\/me\/children\/\d+\/guardians$/, () => ({ status: 200, body: { data: OTHER_GUARDIANS } })],
  [/^\/me\/children\/\d+\/medical$/, () => ({ status: 200, body: { data: MEDICAL } })],
  [/^\/me\/children\/\d+\/results$/, () => ({ status: 200, body: { data: RESULTS } })],
  [/^\/me\/children\/\d+\/attendance$/, () => ({ status: 200, body: { data: ATTENDANCE } })],
  [/^\/me\/children\/\d+\/discipline$/, () => ({ status: 200, body: { data: DISCIPLINE } })],
  [/^\/me\/children\/\d+\/timetable$/, () => ({ status: 200, body: { data: TIMETABLE } })],
  [/^\/me\/children\/\d+\/fees$/, () => ({ status: 200, body: { data: FEES } })],
  [/^\/me\/children\/\d+\/documents$/, () => ({ status: 200, body: { data: DOCUMENTS } })],
  [/^\/me\/children\/\d+\/invoices\/(\d+)$/, (_p, m) => ({ status: 200, body: { data: { ...FEES.invoices.find((i) => i.id === Number(m[1])), currency: XAF, lines: FEES.structure } } })],
  [/^\/me\/children\/\d+\/receipts\/(\d+)$/, (_p, m) => ({ status: 200, body: { data: { ...PAYMENTS.find((p) => p.id === Number(m[1])), school: 'Heritage Bilingual College', verification_code: 'RCP-2026-0700158' } } })],

  // The real server answers 501 here until a gateway exists. The harness must
  // answer the same, or the payment screens photograph a fiction.
  [/^\/me\/children\/\d+\/payments$/, () => ({ status: 501, body: { error: { code: 'not_implemented', message: 'Online payment is not available yet. Please pay at the school office.', details: {} } } })],
];

createServer((req, res) => {
  const path = (req.url ?? '/').replace(/^\/api\/v1/, '');

  res.setHeader('access-control-allow-origin', '*');
  res.setHeader('access-control-allow-headers', '*');
  res.setHeader('access-control-allow-methods', 'GET,POST,PATCH,DELETE,OPTIONS');
  res.setHeader('cache-control', 'no-store');

  if (req.method === 'OPTIONS') {
    res.writeHead(204).end();

    return;
  }

  for (const [pattern, handler] of routes) {
    const match = pattern.exec(path.split('?')[0]);

    if (match) {
      const { status, body } = handler(path, match);
      res.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(body));

      return;
    }
  }

  // Writes the harness does not model: succeed quietly rather than 404, so a
  // screen that posts a read-receipt on mount does not render an error state.
  if (req.method !== 'GET') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ data: { ok: true } }));

    return;
  }

  res.writeHead(404, { 'content-type': 'application/json' });
  res.end(JSON.stringify({ error: { code: 'not_found', message: 'No fixture for ' + path, details: {} } }));
}).listen(PORT, () => console.log(`fixture api on http://127.0.0.1:${PORT}`));
