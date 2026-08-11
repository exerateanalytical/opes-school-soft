import React from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextStyle,
  type ViewStyle,
} from 'react-native';
import Svg, { Circle, G } from 'react-native-svg';

import { colors, layout, radius, shadow, spacing, text, type, weight } from '@/theme';

/**
 * The shared kit. Every screen in `src/screens` is assembled from these, which
 * is what makes 66 screens one design rather than 66 of them.
 *
 * Nothing here reaches for a hex literal - see `theme/tokens.ts` for why.
 */

/* ------------------------------------------------------------------ text -- */

export function Title({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[text.h3, style]}>{children}</Text>;
}

export function Body({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[text.body, style]}>{children}</Text>;
}

export function Muted({ children, style }: { children: React.ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[text.small, style]}>{children}</Text>;
}

/** The all-caps card header used across the reference set. */
export function SectionCap({
  children,
  action,
  onAction,
}: {
  children: React.ReactNode;
  action?: string;
  onAction?: () => void;
}) {
  return (
    <View style={styles.sectionCapRow}>
      <Text style={text.sectionCap}>{children}</Text>
      {action ? (
        <Pressable onPress={onAction} hitSlop={layout.hitSlop}>
          <Text style={styles.link}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

export function SectionHeading({
  children,
  action,
  onAction,
}: {
  children: React.ReactNode;
  action?: string;
  onAction?: () => void;
}) {
  return (
    <View style={styles.sectionCapRow}>
      <Text style={text.h3}>{children}</Text>
      {action ? (
        <Pressable onPress={onAction} hitSlop={layout.hitSlop}>
          <Text style={styles.link}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/* ---------------------------------------------------------------- layout -- */

export function Card({
  children,
  style,
  onPress,
  padded = true,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  onPress?: () => void;
  padded?: boolean;
}) {
  const content = (
    <View style={[styles.card, padded && styles.cardPadded, style]}>{children}</View>
  );

  if (!onPress) return content;

  return (
    <Pressable onPress={onPress} style={({ pressed }) => (pressed ? styles.pressed : undefined)}>
      {content}
    </Pressable>
  );
}

export function Row({
  children,
  style,
  gap = spacing.md,
}: {
  children: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  gap?: number;
}) {
  return <View style={[styles.row, { gap }, style]}>{children}</View>;
}

export function Spacer({ size = spacing.lg }: { size?: number }) {
  return <View style={{ height: size }} />;
}

export function Divider() {
  return <View style={styles.divider} />;
}

/* ----------------------------------------------------------------- chips -- */

export type Tone = 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'gold';

const toneMap: Record<Tone, { bg: string; fg: string }> = {
  primary: { bg: colors.primarySoft, fg: colors.primaryInk },
  success: { bg: colors.successSoft, fg: colors.success },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  info: { bg: colors.infoSoft, fg: colors.info },
  gold: { bg: colors.goldSoft, fg: colors.gold },
  neutral: { bg: colors.surfaceMuted, fg: colors.inkMuted },
};

export function Chip({
  label,
  tone = 'neutral',
  dot = false,
}: {
  label: string;
  tone?: Tone;
  dot?: boolean;
}) {
  const palette = toneMap[tone];

  return (
    <View style={[styles.chip, { backgroundColor: palette.bg }]}>
      {dot ? <View style={[styles.chipDot, { backgroundColor: palette.fg }]} /> : null}
      <Text style={[styles.chipText, { color: palette.fg }]}>{label}</Text>
    </View>
  );
}

/** The red count on the bell and the Messages tab. */
export function Badge({ count }: { count: number }) {
  if (count <= 0) return null;

  return (
    <View style={styles.badge}>
      <Text style={styles.badgeText}>{count > 99 ? '99+' : count}</Text>
    </View>
  );
}

/* --------------------------------------------------------------- buttons -- */

export function Button({
  label,
  onPress,
  variant = 'primary',
  disabled = false,
  busy = false,
  icon,
  style,
}: {
  label: string;
  onPress?: () => void;
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'gold';
  disabled?: boolean;
  busy?: boolean;
  icon?: React.ReactNode;
  style?: StyleProp<ViewStyle>;
}) {
  const inactive = disabled || busy;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy }}
      onPress={inactive ? undefined : onPress}
      style={({ pressed }) => [
        styles.button,
        variant === 'primary' && styles.buttonPrimary,
        variant === 'secondary' && styles.buttonSecondary,
        variant === 'ghost' && styles.buttonGhost,
        variant === 'danger' && styles.buttonDanger,
        variant === 'gold' && styles.buttonGold,
        inactive && styles.buttonDisabled,
        pressed && !inactive && styles.pressed,
        style,
      ]}
    >
      {busy ? (
        <ActivityIndicator
          color={variant === 'secondary' || variant === 'ghost' ? colors.primary : colors.onPrimary}
        />
      ) : (
        <>
          {icon}
          <Text
            style={[
              styles.buttonLabel,
              (variant === 'secondary' || variant === 'ghost') && styles.buttonLabelDark,
            ]}
          >
            {label}
          </Text>
        </>
      )}
    </Pressable>
  );
}

/* ------------------------------------------------------------------ form -- */

export function Field({
  label,
  value,
  onChangeText,
  placeholder,
  secureTextEntry,
  keyboardType,
  autoCapitalize = 'none',
  error,
  editable = true,
  hint,
  multiline = false,
}: {
  label?: string;
  value: string;
  onChangeText: (next: string) => void;
  placeholder?: string;
  secureTextEntry?: boolean;
  keyboardType?: 'default' | 'email-address' | 'phone-pad' | 'numeric';
  autoCapitalize?: 'none' | 'sentences' | 'words';
  error?: string;
  editable?: boolean;
  hint?: string;
  multiline?: boolean;
}) {
  return (
    <View style={styles.field}>
      {label ? <Text style={styles.fieldLabel}>{label}</Text> : null}
      <TextInput
        value={value}
        onChangeText={onChangeText}
        placeholder={placeholder}
        placeholderTextColor={colors.inkFaint}
        secureTextEntry={secureTextEntry}
        keyboardType={keyboardType}
        autoCapitalize={autoCapitalize}
        editable={editable}
        multiline={multiline}
        style={[
          styles.input,
          multiline && styles.inputMultiline,
          !editable && styles.inputDisabled,
          error ? styles.inputError : undefined,
        ]}
      />
      {error ? <Text style={styles.errorText}>{error}</Text> : null}
      {hint && !error ? <Text style={styles.hintText}>{hint}</Text> : null}
    </View>
  );
}

/* ----------------------------------------------------------------- state -- */

export function Loading({ label }: { label?: string }) {
  return (
    <View style={styles.centered}>
      <ActivityIndicator color={colors.primary} />
      {label ? <Text style={[text.small, styles.centeredText]}>{label}</Text> : null}
    </View>
  );
}

/**
 * The empty state doubles as the DENIED state, and the two must read
 * differently: "nothing here yet" and "your school has not shared this" are
 * different facts, and blurring them makes parents distrust the app.
 */
export function EmptyState({
  title,
  body,
  tone = 'neutral',
  action,
  onAction,
}: {
  title: string;
  body?: string;
  tone?: 'neutral' | 'denied';
  action?: string;
  onAction?: () => void;
}) {
  return (
    <View style={styles.empty}>
      <View style={[styles.emptyGlyph, tone === 'denied' && styles.emptyGlyphDenied]} />
      <Text style={[text.title, styles.centeredText]}>{title}</Text>
      {body ? <Text style={[text.small, styles.centeredText]}>{body}</Text> : null}
      {action ? <Button label={action} variant="secondary" onPress={onAction} /> : null}
    </View>
  );
}

/** The "showing saved data" strip, so a stale number never passes as live. */
export function StaleBanner({ label }: { label: string }) {
  return (
    <View style={styles.stale}>
      <Text style={styles.staleText}>{label}</Text>
    </View>
  );
}

/* ------------------------------------------------------------------ data -- */

/** The four-across tile row on the dashboard and the fees header. */
export function StatTile({
  label,
  value,
  caption,
  tone = 'neutral',
  onDark = false,
}: {
  label: string;
  value: string;
  caption?: string;
  tone?: Tone;
  onDark?: boolean;
}) {
  return (
    <View style={[styles.statTile, onDark && styles.statTileDark]}>
      <Text style={[styles.statLabel, onDark && styles.onDarkMuted]}>{label}</Text>
      <Text
        style={[
          styles.statValue,
          onDark && styles.onDark,
          tone === 'danger' && styles.statValueDanger,
        ]}
      >
        {value}
      </Text>
      {caption ? (
        <Text style={[styles.statCaption, onDark && styles.onDarkMuted]}>{caption}</Text>
      ) : null}
    </View>
  );
}

/** A labelled row in a detail list. */
export function DetailRow({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone?: Tone;
}) {
  return (
    <View style={styles.detailRow}>
      <Text style={[text.small, styles.detailLabel]}>{label}</Text>
      {tone ? <Chip label={value} tone={tone} /> : <Text style={styles.detailValue}>{value}</Text>}
    </View>
  );
}

/** The tappable list row used by messages, payments, documents, activities. */
export function ListRow({
  title,
  subtitle,
  trailing,
  trailingTone,
  leading,
  onPress,
  unread = false,
}: {
  title: string;
  subtitle?: string;
  trailing?: string;
  trailingTone?: Tone;
  leading?: React.ReactNode;
  onPress?: () => void;
  unread?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.listRow, pressed && onPress ? styles.pressed : undefined]}
    >
      {leading ? <View style={styles.listLeading}>{leading}</View> : null}
      <View style={styles.listBody}>
        <View style={styles.listTitleRow}>
          <Text style={[styles.listTitle, unread && styles.listTitleUnread]} numberOfLines={1}>
            {title}
          </Text>
          {unread ? <View style={styles.unreadDot} /> : null}
        </View>
        {subtitle ? (
          <Text style={text.small} numberOfLines={2}>
            {subtitle}
          </Text>
        ) : null}
      </View>
      {trailing ? (
        trailingTone ? (
          <Chip label={trailing} tone={trailingTone} />
        ) : (
          <Text style={styles.listTrailing}>{trailing}</Text>
        )
      ) : null}
    </Pressable>
  );
}

/** A horizontal bar, used for subject averages and payment progress. */
export function ProgressBar({ value, tone = 'primary' }: { value: number; tone?: Tone }) {
  const clamped = Math.max(0, Math.min(100, value));

  return (
    <View style={styles.progressTrack}>
      <View
        style={[
          styles.progressFill,
          { width: `${clamped}%`, backgroundColor: toneMap[tone].fg },
        ]}
      />
    </View>
  );
}

/**
 * The donut on the fees and results screens.
 *
 * A real stroked arc, not a rotated-border approximation: the reference set
 * shows 60% and 78% rings, and a two-sided border sweeps only a quarter-turn
 * per side, so the cheap trick under-reports exactly where these screens use
 * it. `strokeDasharray` is honest at every value.
 */
export function ProgressRing({
  value,
  size = 96,
  label,
  caption,
  tone = 'primary',
}: {
  value: number;
  size?: number;
  label?: string;
  caption?: string;
  tone?: Tone;
}) {
  const clamped = Math.max(0, Math.min(100, value));
  const thickness = Math.max(6, Math.round(size * 0.11));
  const r = (size - thickness) / 2;
  const circumference = 2 * Math.PI * r;

  return (
    <View style={{ width: size, height: size }}>
      <Svg width={size} height={size}>
        {/* -90deg so the arc starts at twelve o'clock, as the designs do. */}
        <G rotation={-90} origin={`${size / 2}, ${size / 2}`}>
          <Circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            stroke={colors.surfaceMuted}
            strokeWidth={thickness}
            fill="none"
          />
          <Circle
            cx={size / 2}
            cy={size / 2}
            r={r}
            stroke={toneMap[tone].fg}
            strokeWidth={thickness}
            strokeLinecap="round"
            fill="none"
            strokeDasharray={`${(clamped / 100) * circumference} ${circumference}`}
          />
        </G>
      </Svg>
      <View style={[styles.ringCentre, { width: size, height: size }]}>
        {label ? <Text style={styles.ringLabel}>{label}</Text> : null}
        {caption ? <Text style={styles.ringCaption}>{caption}</Text> : null}
      </View>
    </View>
  );
}

/** A circular initials avatar. Photos are behind signed URLs, so this is the
 *  honest default rather than a broken image. */
export function Avatar({
  name,
  size = layout.avatar.md,
  tone = 'primary',
}: {
  name: string;
  size?: number;
  tone?: Tone;
}) {
  const initials = name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');

  return (
    <View
      style={[
        styles.avatar,
        {
          width: size,
          height: size,
          borderRadius: size / 2,
          backgroundColor: toneMap[tone].bg,
        },
      ]}
    >
      <Text style={[styles.avatarText, { color: toneMap[tone].fg, fontSize: size * 0.36 }]}>
        {initials || '?'}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  sectionCapRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
  },
  link: { fontSize: type.small, fontWeight: weight.semibold, color: colors.primaryInk },

  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    ...shadow.card,
  },
  cardPadded: { padding: spacing.lg },
  pressed: { opacity: 0.75 },

  row: { flexDirection: 'row', alignItems: 'center' },
  divider: { height: 1, backgroundColor: colors.divider, marginVertical: spacing.md },

  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs + 2,
    borderRadius: radius.pill,
    alignSelf: 'flex-start',
  },
  chipDot: { width: 6, height: 6, borderRadius: 3 },
  chipText: { fontSize: type.label, fontWeight: weight.semibold },

  badge: {
    minWidth: 18,
    height: 18,
    paddingHorizontal: 5,
    borderRadius: radius.pill,
    backgroundColor: colors.danger,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { color: colors.white, fontSize: type.caption, fontWeight: weight.bold },

  button: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    height: 52,
    borderRadius: radius.md,
    paddingHorizontal: spacing.lg,
  },
  buttonPrimary: { backgroundColor: colors.primary },
  buttonSecondary: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.borderStrong,
  },
  buttonGhost: { backgroundColor: 'transparent' },
  buttonDanger: { backgroundColor: colors.danger },
  buttonGold: { backgroundColor: colors.gold },
  buttonDisabled: { opacity: 0.5 },
  buttonLabel: { color: colors.onPrimary, fontSize: type.bodyLg, fontWeight: weight.semibold },
  buttonLabelDark: { color: colors.primary },

  field: { gap: spacing.sm },
  fieldLabel: { fontSize: type.small, fontWeight: weight.medium, color: colors.inkMuted },
  input: {
    height: 52,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    paddingHorizontal: spacing.lg,
    fontSize: type.bodyLg,
    color: colors.ink,
  },
  inputMultiline: { height: 120, paddingTop: spacing.md, textAlignVertical: 'top' },
  inputDisabled: { backgroundColor: colors.surfaceMuted, color: colors.inkMuted },
  inputError: { borderColor: colors.danger },
  errorText: { fontSize: type.small, color: colors.danger },
  hintText: { fontSize: type.small, color: colors.inkFaint },

  centered: { alignItems: 'center', justifyContent: 'center', padding: spacing.xl, gap: spacing.md },
  centeredText: { textAlign: 'center' },

  empty: { alignItems: 'center', gap: spacing.md, padding: spacing.xl },
  emptyGlyph: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.primarySoft,
    marginBottom: spacing.sm,
  },
  emptyGlyphDenied: { backgroundColor: colors.surfaceMuted },

  stale: {
    backgroundColor: colors.warningSoft,
    paddingVertical: spacing.sm,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.sm,
  },
  staleText: { fontSize: type.small, color: colors.warning, fontWeight: weight.medium },

  statTile: {
    flex: 1,
    gap: spacing.xs,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.sm,
    alignItems: 'center',
  },
  statTileDark: { backgroundColor: 'transparent' },
  statLabel: { fontSize: type.label, color: colors.inkMuted, textAlign: 'center' },
  statValue: { fontSize: type.h3, fontWeight: weight.bold, color: colors.ink },
  statValueDanger: { color: colors.danger },
  statCaption: { fontSize: type.caption, color: colors.inkFaint, textAlign: 'center' },
  onDark: { color: colors.onPrimary },
  onDarkMuted: { color: colors.onPrimaryMuted },

  detailRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.md,
    gap: spacing.lg,
  },
  detailLabel: { flexShrink: 0 },
  detailValue: {
    fontSize: type.body,
    fontWeight: weight.medium,
    color: colors.ink,
    flexShrink: 1,
    textAlign: 'right',
  },

  listRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
  },
  listLeading: { width: 40, alignItems: 'center' },
  listBody: { flex: 1, gap: 2 },
  listTitleRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  listTitle: { fontSize: type.body, fontWeight: weight.medium, color: colors.ink, flexShrink: 1 },
  listTitleUnread: { fontWeight: weight.bold },
  listTrailing: { fontSize: type.body, fontWeight: weight.semibold, color: colors.ink },
  unreadDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.danger },

  progressTrack: {
    height: 8,
    borderRadius: radius.pill,
    backgroundColor: colors.surfaceMuted,
    overflow: 'hidden',
    flex: 1,
  },
  progressFill: { height: '100%', borderRadius: radius.pill },

  ringCentre: { position: 'absolute', alignItems: 'center', justifyContent: 'center' },
  ringLabel: { fontSize: type.h3, fontWeight: weight.bold, color: colors.ink },
  ringCaption: { fontSize: type.label, color: colors.inkMuted },

  avatar: { alignItems: 'center', justifyContent: 'center' },
  avatarText: { fontWeight: weight.bold },
});
