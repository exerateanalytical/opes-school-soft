import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  Muted,
  ProgressBar,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  StatTile,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/academic-performance-analytics.png`.
 *
 * Every number here is read from the stored report-card snapshots, never
 * derived on the client. A trend line the app computed itself would eventually
 * disagree with the printed bulletin, and the bulletin is the document a parent
 * can carry to the office - so when the snapshot has no figure, this shows a
 * dash rather than an average of what it happens to have.
 */
export default function AcademicPerformanceAnalytics(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.trends')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            <>
              <Card>
                <SectionCap>{t('academics.performanceAtAGlance')}</SectionCap>
                <Row>
                  <StatTile
                    label={t('academics.examsTaken')}
                    value={String(state.data.data.periods.length)}
                  />
                  <StatTile
                    label={t('academics.totalSubjects')}
                    value={String(
                      (
                        (state.data.data.periods[0]?.payload.subjects ?? []) as unknown[]
                      ).length || '—',
                    )}
                  />
                </Row>
              </Card>

              <Card>
                <SectionCap>{t('academics.byTerm')}</SectionCap>

                {state.data.data.periods.map((period) => {
                  const average = period.payload.general_average as number | string | undefined;
                  const numeric = typeof average === 'number' ? average : Number(average ?? NaN);

                  return (
                    <View key={period.snapshot_id} style={styles.row}>
                      <View style={styles.rowHead}>
                        <Text style={styles.periodName}>{period.period.name}</Text>
                        <Text style={styles.periodValue}>
                          {Number.isFinite(numeric) ? `${numeric}%` : '—'}
                        </Text>
                      </View>
                      <ProgressBar value={Number.isFinite(numeric) ? numeric : 0} />
                    </View>
                  );
                })}
              </Card>

              <Card>
                <Muted>{t('academics.notPublished')}</Muted>
              </Card>
            </>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { gap: spacing.sm, paddingVertical: spacing.md },
  rowHead: { flexDirection: 'row', justifyContent: 'space-between' },
  periodName: { fontSize: type.body, fontWeight: weight.medium, color: colors.ink },
  periodValue: { fontSize: type.body, fontWeight: weight.bold, color: colors.primary },
});
