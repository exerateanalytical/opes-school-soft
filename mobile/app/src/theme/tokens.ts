/**
 * The locked visual system, transcribed from the 81 reference screens in
 * `mobile/*.png`.
 *
 * These are the ONLY colours, radii, shadows and steps this app may use. The
 * build plan's Slice G rule is literal: "No invented greens; tokens only." A
 * hex literal anywhere outside this file is a bug, because the moment two
 * screens spell the same green differently the design stops being a system and
 * becomes 66 opinions.
 *
 * HONESTY NOTE, carried from the handover: these values were read off the
 * reference PNGs by eye, not sampled from a design file, and the rendered app
 * has not been diffed against those PNGs - that needs a simulator and a
 * screenshot harness, which this environment does not have. They are faithful,
 * not proven. See `mobile/app/README.md`.
 */

export const colors = {
  /** The header, the primary button, the active nav item. */
  primary: '#0B3B2B',
  /** The deeper green the header gradient falls to, and the dark nav bar. */
  primaryDeep: '#072A1E',
  /** Selected cards, chips, the "Active" pill background. */
  primarySoft: '#E8F3EC',
  /** Body text on a soft-green surface, and secondary links. */
  primaryInk: '#0F5132',

  /** The crest, the header's bottom curve, active accents, "Upcoming". */
  gold: '#C9971C',
  goldLight: '#E8B93B',
  goldSoft: '#FDF3E0',

  /** Balance due, overdue rows, the unread badge. */
  danger: '#DC2626',
  dangerSoft: '#FDECEC',

  /** "This Term", pending states. */
  warning: '#E8A020',
  warningSoft: '#FDF3E0',

  /** Positive deltas, "Paid", the success tick. */
  success: '#1E9E4A',
  successSoft: '#E6F5EC',

  /** Subject-tile accents, only ever decorative. */
  info: '#2563EB',
  infoSoft: '#E8F0FE',
  purple: '#7C3AED',
  purpleSoft: '#F1E9FE',

  /** Surfaces. `cream` is the auth-screen background; `canvas` the app one. */
  white: '#FFFFFF',
  canvas: '#F6F7F5',
  cream: '#F7F5EF',
  surface: '#FFFFFF',
  surfaceMuted: '#F2F4F2',

  border: '#E6E8E6',
  borderStrong: '#D3D8D4',
  divider: '#EEF0EE',

  ink: '#14201A',
  inkMuted: '#6B7B72',
  inkFaint: '#98A69E',
  onPrimary: '#FFFFFF',
  onPrimaryMuted: '#B9CFC4',
} as const;

/** 8px grid. Every margin and gap in the app is one of these. */
export const spacing = {
  xxs: 2,
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
  xxxl: 48,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  xl: 20,
  /** The bottom nav's top corners - 28-32px in the reference set. */
  nav: 28,
  pill: 999,
} as const;

export const type = {
  /** Sizes, not styles - weight and colour are chosen at the call site. */
  caption: 11,
  label: 12,
  small: 13,
  body: 14,
  bodyLg: 16,
  title: 18,
  h3: 20,
  h2: 24,
  h1: 28,
  display: 32,
} as const;

export const weight = {
  regular: '400',
  medium: '500',
  semibold: '600',
  bold: '700',
} as const;

/**
 * Two elevations only. The reference screens use a barely-there card lift and
 * a slightly stronger one for the nav bar; anything more competes with the
 * header curve for attention.
 */
export const shadow = {
  card: {
    shadowColor: '#0B3B2B',
    shadowOpacity: 0.06,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2,
  },
  nav: {
    shadowColor: '#0B3B2B',
    shadowOpacity: 0.12,
    shadowRadius: 20,
    shadowOffset: { width: 0, height: -4 },
    elevation: 12,
  },
} as const;

/** Header heights, so a screen's scroll offset and the curve agree. */
export const layout = {
  headerMin: 96,
  headerTall: 168,
  curveHeight: 28,
  navHeight: 68,
  avatar: { sm: 32, md: 44, lg: 56, xl: 88 },
  hitSlop: { top: 8, bottom: 8, left: 8, right: 8 },
} as const;

export type Colors = typeof colors;
