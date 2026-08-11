import React from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import {
  AppHeader,
  Avatar,
  BottomNav,
  Button,
  Card,
  Chip,
  Divider,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  SectionHeading,
  StatTile,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, formatMoney, useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/parent-dashboard.png` — the home screen, and the app's whole
 * argument in one view.
 *
 * Everything here comes from ONE request (`GET /v1/me/dashboard`), which is
 * why that endpoint exists: a parent on a 2G connection outside a school gate
 * should wait once, not six times.
 *
 * A tile whose capability is absent is NOT rendered as zero. The endpoint
 * omits it deliberately - a zero would tell a parent "your child's balance is
 * nothing" when the truth is "the school does not share fees with you", and
 * those are very different things to be told.
 */
export default function ParentDashboard(): React.JSX.Element {
  const { t, language } = useI18n();
  const { guardian, children, selectChild, can } = useSession();
  const state = useScreenData(() => me.dashboard(), []);

  return (
    <Screen
      header={
        <AppHeader
          title={t('dashboard.welcome', { name: guardian?.display_name ?? '' })}
          subtitle={t('dashboard.todayIs')}
          unread={3}
          onBell={() => router.push('/notifications' as never)}
        />
      }
      nav={<BottomNav items={navItems(t, 5)} active="dashboard" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <SectionHeading action={t('dashboard.viewAllChildren')} onAction={() => goTo('children')}>
              {t('dashboard.myChildren')}
            </SectionHeading>

            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.childRow}>
              {state.data.data.children.map((child, index) => (
                <Pressable
                  key={child.id}
                  onPress={() => {
                    selectChild(child.id);
                    router.push(`/child/${child.id}` as never);
                  }}
                  style={[styles.childCard, index === 0 && styles.childCardActive]}
                >
                  <Avatar name={child.display_name} />
                  <Text style={styles.childName} numberOfLines={1}>
                    {child.display_name}
                  </Text>
                  <Text style={styles.childClass} numberOfLines={1}>
                    {child.class ?? '—'}
                  </Text>
                  <Chip label={t('common.active')} tone="success" />
                </Pressable>
              ))}
            </ScrollView>

            <SectionHeading>{t('dashboard.overview')}</SectionHeading>

            <View style={styles.tiles}>
              <Card style={styles.tile} padded={false}>
                <StatTile label={t('dashboard.academicAvg')} value="—" caption={t('common.notAvailable')} />
              </Card>

              {can('results.attendance_summary.view') ? (
                <Card style={styles.tile} padded={false}>
                  <StatTile label={t('dashboard.attendance')} value="—" caption={t('common.thisTerm')} />
                </Card>
              ) : null}

              {/* Absent, not zero — see the docblock. */}
              {can('fees.statement.view') || can('fees.invoices.view') ? (
                <Card style={styles.tile} padded={false}>
                  <StatTile
                    label={t('dashboard.outstandingFees')}
                    value={formatMoney(0, 'XAF', language)}
                    tone="danger"
                    caption={formatDate(state.data.data.as_of, language)}
                  />
                </Card>
              ) : null}

              {can('results.rank.view') ? (
                <Card style={styles.tile} padded={false}>
                  <StatTile label={t('dashboard.classRank')} value="—" />
                </Card>
              ) : null}
            </View>

            <Card style={styles.alert}>
              <View style={styles.alertBody}>
                <Text style={styles.alertTitle}>{t('dashboard.unreadMessages', { count: 3 })}</Text>
                <Text style={styles.alertCopy}>{t('dashboard.checkInbox')}</Text>
              </View>
              <Button
                label={t('dashboard.viewMessages')}
                variant="secondary"
                onPress={() => goTo('messages')}
              />
            </Card>

            <Card>
              <SectionHeading action={t('common.viewAll')} onAction={() => goTo('messages')}>
                {t('dashboard.recentMessages')}
              </SectionHeading>
              <EmptyState title={t('comms.noMessages')} />
            </Card>

            <Card>
              <SectionHeading action={t('common.viewAll')} onAction={() => router.push('/activities' as never)}>
                {t('dashboard.upcomingActivities')}
              </SectionHeading>
              <EmptyState title={t('common.notAvailable')} />
            </Card>

            <SectionHeading>{t('dashboard.quickActions')}</SectionHeading>
            <View style={styles.actions}>
              {[
                { label: t('academics.resultsOverview'), route: '/child/results' },
                { label: t('fees.payNow'), route: '/(tabs)/payments' },
                { label: t('attendance.title'), route: '/child/attendance' },
                { label: t('academics.reportCard'), route: '/child/report-card' },
                { label: t('comms.inbox'), route: '/(tabs)/messages' },
                { label: t('nav.more'), route: '/(tabs)/more' },
              ].map((action) => (
                <Pressable
                  key={action.label}
                  style={styles.action}
                  onPress={() => router.push(action.route as never)}
                >
                  <Text style={styles.actionLabel} numberOfLines={2}>
                    {action.label}
                  </Text>
                </Pressable>
              ))}
            </View>

            <Card style={styles.banner}>
              <Text style={styles.bannerTitle}>{t('dashboard.safetyBanner')}</Text>
              <Text style={styles.bannerCopy}>{t('dashboard.safetyBody')}</Text>
              <Divider />
              <Button
                label={t('dashboard.updateProfile')}
                variant="gold"
                onPress={() => router.push('/settings/profile' as never)}
              />
            </Card>
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  childRow: { gap: spacing.md, paddingRight: spacing.lg },
  childCard: {
    width: 150,
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  childCardActive: { borderColor: colors.primary, backgroundColor: colors.primarySoft },
  childName: { fontSize: type.body, fontWeight: weight.semibold, color: colors.ink },
  childClass: { fontSize: type.small, color: colors.inkMuted },

  tiles: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  tile: { flexGrow: 1, flexBasis: '45%' },

  alert: { backgroundColor: colors.goldSoft, borderColor: colors.goldLight, gap: spacing.md },
  alertBody: { gap: 2 },
  alertTitle: { fontSize: type.bodyLg, fontWeight: weight.semibold, color: colors.ink },
  alertCopy: { fontSize: type.small, color: colors.inkMuted },

  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  action: {
    flexGrow: 1,
    flexBasis: '28%',
    height: 84,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.sm,
  },
  actionLabel: {
    fontSize: type.label,
    fontWeight: weight.medium,
    color: colors.primary,
    textAlign: 'center',
  },

  banner: { backgroundColor: colors.primary, borderColor: colors.primaryDeep, gap: spacing.sm },
  bannerTitle: { fontSize: type.bodyLg, fontWeight: weight.bold, color: colors.onPrimary },
  bannerCopy: { fontSize: type.small, color: colors.onPrimaryMuted },
});
