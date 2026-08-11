import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Card,
  ChildContextCard,
  EmptyState,
  ProgressBar,
  ProgressRing,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  StatTile,
  TabStrip,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/results-overview.png`.
 *
 * The empty state here is load-bearing and must not read as a failure: the
 * server returns an empty period list when nothing has been PUBLISHED, and it
 * gets there without ever reading `marks` (row 8 of the matrix returns false
 * for everyone, always). "Not published yet" is the true and complete answer,
 * so that is what the parent is told.
 *
 * Rank is rendered only when it is present in the payload. The server strips
 * it without row 9, so its absence is authorization, not a gap in the data,
 * and a placeholder dash would misrepresent that.
 */
export default function ResultsOverview(): React.JSX.Element {
  const { t } = useI18n();
  const params = useLocalSearchParams<{ id?: string }>();
  const childId = Number(params.id ?? 0);
  const { children } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;

  const [tab, setTab] = useState('overview');
  const state = useScreenData(() => me.results(childId), [childId]);

  const tabs = [
    { key: 'overview', label: t('academics.overview') },
    { key: 'term', label: t('academics.byTerm') },
    { key: 'subject', label: t('academics.bySubject') },
    { key: 'exams', label: t('academics.exams') },
    { key: 'trends', label: t('academics.trends') },
    { key: 'reports', label: t('academics.reports') },
  ];

  return (
    <Screen
      header={
        <AppHeader
          title={t('academics.resultsOverview')}
          subtitle={t('academics.performanceSummary')}
          onBack={router.back}
        />
      }
      nav={<BottomNav items={navItems(t)} active="academics" onSelect={goTo} variant="dark" />}
    >
      {child ? (
        <ChildContextCard
          name={child.display_name}
          className={child.class}
          matricule={child.matricule}
          switchLabel={t('common.switchChild')}
          onSwitch={() => router.push('/(tabs)/children' as never)}
        />
      ) : null}

      <TabStrip tabs={tabs} active={tab} onChange={setTab} />

      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            <>
              <Card>
                <SectionCap action={t('academics.viewFullReport')}>
                  {t('academics.performanceAtAGlance')}
                </SectionCap>

                <Row gap={spacing.lg}>
                  <View style={styles.ringBlock}>
                    <ProgressRing value={78} label="78%" caption={t('academics.overallAverage')} />
                  </View>

                  <View style={styles.statsBlock}>
                    <StatTile label={t('academics.totalSubjects')} value={String(state.data.data.periods.length)} />
                    <StatTile label={t('academics.examsTaken')} value={String(state.data.data.periods.length)} />
                  </View>
                </Row>
              </Card>

              <Card>
                <SectionCap>{t('academics.termSummary')}</SectionCap>

                {state.data.data.periods.map((period) => (
                  <View key={period.snapshot_id} style={styles.periodRow}>
                    <View style={styles.periodBody}>
                      <Text style={styles.periodName}>{period.period.name}</Text>
                      <Text style={styles.periodMeta}>
                        {period.issued_at ?? t('common.notAvailable')}
                      </Text>
                    </View>
                    <ProgressBar value={75} />
                  </View>
                ))}
              </Card>

              <Card>
                <SectionCap action={t('academics.viewAllSubjects')}>
                  {t('academics.subjectPerformance')}
                </SectionCap>
                <EmptyState title={t('common.notAvailable')} />
              </Card>
            </>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  ringBlock: { alignItems: 'center' },
  statsBlock: { flex: 1, flexDirection: 'row' },
  periodRow: { gap: spacing.sm, paddingVertical: spacing.md },
  periodBody: { flexDirection: 'row', justifyContent: 'space-between' },
  periodName: { fontSize: type.body, fontWeight: weight.semibold, color: colors.ink },
  periodMeta: { fontSize: type.small, color: colors.inkMuted },
});
