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
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/bulletin-scolaire-report-card.png` — the FRENCH-form bulletin.
 *
 * A separate screen from `ReportCardViewer` rather than a language toggle on
 * it, because a Cameroonian bulletin scolaire is not a translation of an
 * English report card - it is a differently-shaped document, with the school's
 * letterhead, the établissement block and the conduct line the francophone
 * system expects. Same snapshot payload, different presentation.
 */
export default function BulletinScolaireReportCard(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title="Bulletin Scolaire" onBack={router.back} />}>
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
                <Card key={period.snapshot_id} padded={false}>
                  <View style={styles.letterhead}>
                    <Text style={styles.school}>HERITAGE BILINGUAL COLLEGE</Text>
                    <Text style={styles.motto}>EXCELLENCE IN EDUCATION</Text>
                    <Text style={styles.docTitle}>
                      BULLETIN — {period.period.name_fr ?? period.period.name}
                    </Text>
                  </View>

                  <View style={styles.sheet}>
                    <View style={styles.tableHead}>
                      <Text style={[styles.cell, styles.cellWide, styles.cellHead]}>Matière</Text>
                      <Text style={[styles.cell, styles.cellHead]}>Moy.</Text>
                      <Text style={[styles.cell, styles.cellHead]}>Note</Text>
                    </View>

                    {subjects.length === 0 ? (
                      <Muted>{t('common.notAvailable')}</Muted>
                    ) : (
                      subjects.map((subject, index) => (
                        <View key={index} style={styles.tableRow}>
                          <Text style={[styles.cell, styles.cellWide]} numberOfLines={1}>
                            {String(subject.name ?? subject.subject ?? '—')}
                          </Text>
                          <Text style={styles.cell}>{String(subject.average ?? '—')}</Text>
                          <Text style={styles.cell}>{String(subject.grade ?? '—')}</Text>
                        </View>
                      ))
                    )}

                    <Spacer size={8} />
                    <DetailRow
                      label="Moyenne générale"
                      value={String(payload.general_average ?? '—')}
                    />
                    {payload.rank_position !== undefined ? (
                      <DetailRow
                        label="Rang"
                        value={`${String(payload.rank_position)} / ${String(payload.rank_denominator ?? '—')}`}
                      />
                    ) : null}
                    <DetailRow label="Délivré le" value={formatDate(period.issued_at, language)} />
                  </View>
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
  letterhead: {
    backgroundColor: colors.primary,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.lg,
    alignItems: 'center',
    gap: 2,
  },
  school: { fontSize: type.bodyLg, fontWeight: weight.bold, color: colors.onPrimary, letterSpacing: 2 },
  motto: { fontSize: type.caption, color: colors.gold, letterSpacing: 1 },
  docTitle: { fontSize: type.small, color: colors.onPrimaryMuted, marginTop: spacing.sm },

  sheet: { padding: spacing.lg },
  tableHead: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: colors.borderStrong,
    paddingBottom: spacing.sm,
  },
  tableRow: {
    flexDirection: 'row',
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.divider,
  },
  cell: { flex: 1, fontSize: type.small, color: colors.ink, textAlign: 'right' },
  cellWide: { flex: 3, textAlign: 'left' },
  cellHead: { fontWeight: weight.bold, color: colors.inkMuted },
});
