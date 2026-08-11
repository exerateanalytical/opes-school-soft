export { colors, spacing, radius, type, weight, shadow, layout } from './tokens';
export type { Colors } from './tokens';

import { colors, type, weight } from './tokens';
import type { TextStyle } from 'react-native';

/**
 * The named text roles the screens use, so "a section heading" is one decision
 * made once rather than a size and a weight guessed 66 times.
 */
export const text = {
  display: { fontSize: type.display, fontWeight: weight.bold, color: colors.ink },
  h1: { fontSize: type.h1, fontWeight: weight.bold, color: colors.ink },
  h2: { fontSize: type.h2, fontWeight: weight.bold, color: colors.ink },
  h3: { fontSize: type.h3, fontWeight: weight.semibold, color: colors.ink },
  title: { fontSize: type.title, fontWeight: weight.semibold, color: colors.ink },
  body: { fontSize: type.body, fontWeight: weight.regular, color: colors.ink },
  bodyLg: { fontSize: type.bodyLg, fontWeight: weight.regular, color: colors.ink },
  muted: { fontSize: type.body, fontWeight: weight.regular, color: colors.inkMuted },
  small: { fontSize: type.small, fontWeight: weight.regular, color: colors.inkMuted },
  label: {
    fontSize: type.label,
    fontWeight: weight.semibold,
    color: colors.inkMuted,
    letterSpacing: 0.6,
  },
  /** The all-caps card headers ("FEES OVERVIEW", "DUE FEES"). */
  sectionCap: {
    fontSize: type.small,
    fontWeight: weight.bold,
    color: colors.ink,
    letterSpacing: 0.8,
  },
  onPrimary: { fontSize: type.body, fontWeight: weight.regular, color: colors.onPrimary },
} satisfies Record<string, TextStyle>;
