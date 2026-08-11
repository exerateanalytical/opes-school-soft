import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  DetailRow,
  EmptyState,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/report-card-viewer.png` and `mobile/bulletin-scolaire-report-card.png`.
 *
 * Renders the stored SNAPSHOT payload - the same immutable object the printed
 * bulletin was rendered from - rather than a live query. That is not a
 * shortcut: 01-assessment 13.3 requires the parent's copy and the school's copy
 * to be the same numbers by construction, and a live recomputation would drift
 * the moment a mark was corrected after publication.
 *
 * There is no download button. `can_download` (row 6) is honoured by showing
 * the verification route instead, because the only path to the signed PDF is
 * gated on a staff permission - see ChildDocuments for the full argument.
 */
export default function ReportCardViewer(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.reportCard')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            state.data.data.periods.map((period) => {
              const payload = period.payload as Record<string, unknown>;
              const subjects = (payload.subjects ?? []) as Record<string, unknown>[];

              return (
                <Card key={period.snapshot_id}>
                  <View style={styles.sheetHead}>
                    <Text style={styles.sheetTitle}>{period.period.name}</Text>
                    <Text style={styles.sheetMeta}>
                      {formatDate(period.issued_at, language)}
                    </Text>
                  </View>

                  <Spacer size={8} />
                  <SectionCap>{t('academics.subjectPerformance')}</SectionCap>

                  {subjects.length === 0 ? (
                    <Muted>{t('common.notAvailable')}</Muted>
                  ) : (
                    subjects.map((subject, index) => (
                      <DetailRow
                        key={index}
                        label={String(subject.name ?? subject.subject ?? '—')}
                        value={String(subject.average ?? subject.mark ?? '—')}
                      />
                    ))
                  )}

                  {payload.general_average !== undefined ? (
                    <>
                      <Spacer size={8} />
                      <DetailRow
                        label={t('academics.overallAverage')}
                        value={String(payload.general_average)}
                      />
                    </>
                  ) : null}

                  {/* Absent without row 9 - authorization, not a data gap. */}
                  {payload.rank_position !== undefined ? (
                    <DetailRow
                      label={t('academics.positionInClass')}
                      value={`${String(payload.rank_position)} / ${String(payload.rank_denominator ?? '—')}`}
                    />
                  ) : null}

                  {period.promotion ? (
                    <DetailRow
                      label={t('academics.reports')}
                      value={String(
                        (period.promotion as Record<string, unknown>).decision ?? '—',
                      )}
                      tone="success"
                    />
                  ) : null}
                </Card>
              );
            })
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  sheetHead: {
    borderLeftWidth: 3,
    borderLeftColor: colors.gold,
    paddingLeft: spacing.md,
    gap: 2,
  },
  sheetTitle: { fontSize: type.title, fontWeight: weight.bold, color: colors.primary },
  sheetMeta: { fontSize: type.small, color: colors.inkMuted },
});
