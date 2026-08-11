import React from 'react';
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
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';

/**
 * `mobile/transcript-viewer.png`.
 *
 * A transcript is a multi-year document and the P0 API has no endpoint for one
 * (spec §1 lists transcripts under non-goals). What this screen can honestly
 * show is every PUBLISHED period the guardian is entitled to, oldest first,
 * which is the transcript's content for the years the school has published -
 * and it says so rather than implying the record is complete.
 */
export default function TranscriptViewer(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.transcript')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            <>
              <Card>
                <SectionCap>{t('academics.transcript')}</SectionCap>

                {[...state.data.data.periods]
                  .sort((a, b) => (a.issued_at ?? '').localeCompare(b.issued_at ?? ''))
                  .map((period) => (
                    <DetailRow
                      key={period.snapshot_id}
                      label={
                        language === 'fr' && period.period.name_fr
                          ? period.period.name_fr
                          : period.period.name
                      }
                      value={String(
                        (period.payload as Record<string, unknown>).general_average ?? '—',
                      )}
                    />
                  ))}
              </Card>

              <Card>
                <Muted>
                  {t('academics.notPublished')} — {formatDate(new Date().toISOString(), language)}
                </Muted>
              </Card>
            </>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
