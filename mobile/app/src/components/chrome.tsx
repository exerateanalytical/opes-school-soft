import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import Svg, { Path } from 'react-native-svg';

import { colors, layout, radius, shadow, spacing, type, weight } from '@/theme';
import { Avatar, Badge, Chip } from './primitives';

/**
 * The app's chrome: the deep-green header with its gold curve, the child
 * context strip, the tab strip and the two bottom navigations the reference
 * set uses.
 *
 * There ARE two navigations in the source screens and it is not an
 * inconsistency to be flattened: the light bar (dashboard) and the dark bar
 * with a raised centre crest (fees, results). They are kept as one component
 * with a `variant`, so a screen picks the one its reference PNG shows and the
 * spacing rules stay shared.
 */

/* ----------------------------------------------------------------- curve -- */

/**
 * The gold-edged wave that closes every green header.
 *
 * A single SVG path rather than a background image, so it recolours with the
 * school's brand colour (the platform lets a school pick one - see the
 * `feat(branding)` commit) instead of shipping one PNG per school.
 */
export function HeaderCurve({
  color = colors.primary,
  accent = colors.gold,
  height = layout.curveHeight,
}: {
  color?: string;
  accent?: string;
  height?: number;
}) {
  return (
    <View style={{ height }}>
      <Svg width="100%" height={height} viewBox="0 0 375 28" preserveAspectRatio="none">
        <Path d="M0,0 H375 V10 C280,30 95,4 0,24 Z" fill={color} />
        <Path
          d="M0,24 C95,4 280,30 375,10 V14 C280,34 95,8 0,28 Z"
          fill={accent}
          opacity={0.9}
        />
      </Svg>
    </View>
  );
}

/* ---------------------------------------------------------------- header -- */

export function AppHeader({
  title,
  subtitle,
  onBack,
  unread = 0,
  onBell,
  right,
  compact = false,
}: {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  unread?: number;
  onBell?: () => void;
  right?: React.ReactNode;
  compact?: boolean;
}) {
  const insets = useSafeAreaInsets();

  return (
    <View style={styles.header}>
      <View style={[styles.headerInner, { paddingTop: insets.top + spacing.sm }]}>
        <View style={styles.headerRow}>
          {onBack ? (
            <Pressable onPress={onBack} hitSlop={layout.hitSlop} style={styles.backButton}>
              <Text style={styles.backGlyph}>←</Text>
            </Pressable>
          ) : null}

          <View style={styles.headerTitles}>
            <Text style={[styles.headerTitle, compact && styles.headerTitleCompact]} numberOfLines={1}>
              {title}
            </Text>
            {subtitle ? (
              <Text style={styles.headerSubtitle} numberOfLines={1}>
                {subtitle}
              </Text>
            ) : null}
          </View>

          {right}

          {onBell ? (
            <Pressable onPress={onBell} hitSlop={layout.hitSlop} style={styles.bell}>
              <Text style={styles.bellGlyph}>🔔</Text>
              <View style={styles.bellBadge}>
                <Badge count={unread} />
              </View>
            </Pressable>
          ) : null}
        </View>
      </View>
    </View>
  );
}

/**
 * The white card that names the child every child-scoped screen is about, with
 * the "Switch Child" affordance. Present on every such screen in the reference
 * set, and worth being a component: a parent with three children must never
 * have to guess whose balance they are looking at.
 */
export function ChildContextCard({
  name,
  className,
  matricule,
  schoolName,
  status = 'Active',
  onSwitch,
  switchLabel,
  onDark = false,
}: {
  name: string;
  className?: string | null;
  matricule?: string;
  schoolName?: string;
  status?: string;
  onSwitch?: () => void;
  switchLabel: string;
  onDark?: boolean;
}) {
  return (
    <View style={[styles.childCard, onDark && styles.childCardDark]}>
      <Avatar name={name} size={layout.avatar.lg} />

      <View style={styles.childBody}>
        <View style={styles.childNameRow}>
          <Text style={[styles.childName, onDark && styles.onDark]} numberOfLines={1}>
            {name}
          </Text>
          <Chip label={status} tone="success" dot />
        </View>

        <Text style={[styles.childMeta, onDark && styles.onDarkMuted]} numberOfLines={1}>
          {[className, matricule].filter(Boolean).join('  •  ')}
        </Text>

        {schoolName ? (
          <Text style={[styles.childMeta, onDark && styles.onDarkMuted]} numberOfLines={1}>
            {schoolName}
          </Text>
        ) : null}
      </View>

      {onSwitch ? (
        <Pressable onPress={onSwitch} style={styles.switchButton}>
          <Text style={styles.switchLabel}>{switchLabel}</Text>
          <Text style={styles.switchChevron}>›</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/* ------------------------------------------------------------------ tabs -- */

export type TabItem = { key: string; label: string };

/** The scrollable segmented strip on results, health and fees. */
export function TabStrip({
  tabs,
  active,
  onChange,
}: {
  tabs: TabItem[];
  active: string;
  onChange: (key: string) => void;
}) {
  return (
    <View style={styles.tabStrip}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabRow}>
        {tabs.map((tab) => {
          const isActive = tab.key === active;

          return (
            <Pressable
              key={tab.key}
              onPress={() => onChange(tab.key)}
              style={[styles.tab, isActive && styles.tabActive]}
            >
              <Text style={[styles.tabLabel, isActive && styles.tabLabelActive]}>{tab.label}</Text>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

/* -------------------------------------------------------------------- nav -- */

export type NavKey = 'dashboard' | 'children' | 'academics' | 'payments' | 'messages' | 'more';

export type NavItem = { key: NavKey; label: string; glyph: string; badge?: number };

export function BottomNav({
  items,
  active,
  onSelect,
  variant = 'light',
}: {
  items: NavItem[];
  active: NavKey;
  onSelect: (key: NavKey) => void;
  variant?: 'light' | 'dark';
}) {
  const insets = useSafeAreaInsets();
  const dark = variant === 'dark';

  return (
    <View
      style={[
        styles.nav,
        dark && styles.navDark,
        { paddingBottom: Math.max(insets.bottom, spacing.sm) },
      ]}
    >
      {items.map((item) => {
        const isActive = item.key === active;

        return (
          <Pressable
            key={item.key}
            onPress={() => onSelect(item.key)}
            style={styles.navItem}
            accessibilityRole="tab"
            accessibilityState={{ selected: isActive }}
          >
            <View style={styles.navGlyphWrap}>
              <Text
                style={[
                  styles.navGlyph,
                  dark && styles.navGlyphDark,
                  // Gold, not white, is the active colour on the dark bar -
                  // white would be indistinguishable from the inactive items.
                  isActive && (dark ? styles.navGlyphActiveDark : styles.navGlyphActive),
                ]}
              >
                {item.glyph}
              </Text>
              {item.badge ? (
                <View style={styles.navBadge}>
                  <Badge count={item.badge} />
                </View>
              ) : null}
            </View>

            <Text
              style={[
                styles.navLabel,
                dark && styles.navLabelDark,
                isActive && (dark ? styles.navLabelActiveDark : styles.navLabelActive),
              ]}
              numberOfLines={1}
            >
              {item.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/* ---------------------------------------------------------------- screen -- */

/**
 * The frame every screen sits in: green status-bar area, header, gold curve,
 * scrolling body on the canvas, optional nav.
 *
 * Screens do not lay this out themselves - if they did, the curve would land a
 * few pixels differently on each one and the whole set would feel loose.
 */
export function Screen({
  header,
  children,
  nav,
  scroll = true,
  contentStyle,
  headerColor = colors.primary,
}: {
  header?: React.ReactNode;
  children: React.ReactNode;
  nav?: React.ReactNode;
  scroll?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
  headerColor?: string;
}) {
  const body = (
    <View style={[styles.body, contentStyle]}>{children}</View>
  );

  return (
    <View style={styles.screen}>
      {header ? (
        <View style={{ backgroundColor: headerColor }}>
          {header}
          <HeaderCurve color={headerColor} />
        </View>
      ) : (
        <SafeAreaView edges={['top']} style={{ backgroundColor: colors.canvas }} />
      )}

      {scroll ? (
        <ScrollView
          style={styles.scroll}
          contentContainerStyle={styles.scrollContent}
          showsVerticalScrollIndicator={false}
        >
          {body}
        </ScrollView>
      ) : (
        body
      )}

      {nav}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.canvas },
  scroll: { flex: 1 },
  scrollContent: { paddingBottom: spacing.xxxl },
  body: { padding: spacing.lg, gap: spacing.lg },

  header: { backgroundColor: 'transparent' },
  headerInner: { paddingHorizontal: spacing.lg, paddingBottom: spacing.lg },
  headerRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  headerTitles: { flex: 1, gap: 2 },
  headerTitle: { fontSize: type.h2, fontWeight: weight.bold, color: colors.onPrimary },
  headerTitleCompact: { fontSize: type.title },
  headerSubtitle: { fontSize: type.small, color: colors.onPrimaryMuted },

  backButton: {
    width: 44,
    height: 44,
    borderRadius: radius.md,
    backgroundColor: 'rgba(255,255,255,0.12)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  backGlyph: { color: colors.onPrimary, fontSize: type.h3 },

  bell: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  bellGlyph: { fontSize: type.h3 },
  bellBadge: { position: 'absolute', top: 0, right: 0 },

  childCard: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.lg,
    ...shadow.card,
  },
  childCardDark: { backgroundColor: colors.primaryDeep },
  childBody: { flex: 1, gap: 2 },
  childNameRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  childName: { fontSize: type.title, fontWeight: weight.bold, color: colors.ink, flexShrink: 1 },
  childMeta: { fontSize: type.small, color: colors.inkMuted },
  onDark: { color: colors.onPrimary },
  onDarkMuted: { color: colors.onPrimaryMuted },

  switchButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    borderWidth: 1,
    borderColor: colors.primary,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  switchLabel: { fontSize: type.small, fontWeight: weight.semibold, color: colors.primary },
  switchChevron: { fontSize: type.bodyLg, color: colors.primary },

  tabStrip: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    ...shadow.card,
  },
  tabRow: { padding: spacing.xs, gap: spacing.xs },
  tab: {
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    borderRadius: radius.md,
    alignItems: 'center',
  },
  tabActive: { backgroundColor: colors.primary },
  tabLabel: { fontSize: type.small, fontWeight: weight.medium, color: colors.inkMuted },
  tabLabelActive: { color: colors.onPrimary, fontWeight: weight.semibold },

  nav: {
    flexDirection: 'row',
    backgroundColor: colors.surface,
    borderTopLeftRadius: radius.nav,
    borderTopRightRadius: radius.nav,
    paddingTop: spacing.md,
    paddingHorizontal: spacing.sm,
    ...shadow.nav,
  },
  navDark: { backgroundColor: colors.primaryDeep },
  navItem: { flex: 1, alignItems: 'center', gap: spacing.xs },
  navGlyphWrap: { width: 28, height: 24, alignItems: 'center', justifyContent: 'center' },
  navGlyph: { fontSize: 18, color: colors.inkMuted },
  navGlyphDark: { color: colors.onPrimaryMuted },
  navGlyphActive: { color: colors.primary },
  navGlyphActiveDark: { color: colors.gold },
  navBadge: { position: 'absolute', top: -4, right: -8 },
  navLabel: { fontSize: type.caption, color: colors.inkMuted },
  navLabelDark: { color: colors.onPrimaryMuted },
  navLabelActive: { color: colors.primary, fontWeight: weight.semibold },
  navLabelActiveDark: { color: colors.gold, fontWeight: weight.semibold },
});
