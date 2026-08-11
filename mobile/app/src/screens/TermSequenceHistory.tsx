import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  Chip,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';

/**
 * `mobile/term-sequence-history.png`.
 *
 * The promotion decision appears only when the server sent one, which happens
 * only with row 10 AND an APPLIED decision. A pending or draft decision is not
 * shown at all: telling a parent their child is provisionally repeating a year
 * before the school has applied that decision would be the worst possible
 * false alarm.
 */
export default function TermSequenceHistory(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.byTerm')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            <Card>
              <SectionCap>{t('academics.termSummary')}</SectionCap>

              {state.data.data.periods.map((period) => (
                <ListRow
                  key={period.snapshot_id}
                  title={
                    language === 'fr' && period.period.name_fr
                      ? period.period.name_fr
                      : period.period.name
                  }
                  subtitle={
                    period.issued_at
                      ? formatDate(period.issued_at, language)
                      : t('common.notAvailable')
                  }
                  trailing={
                    period.promotion
                      ? String((period.promotion as Record<string, unknown>).decision ?? '')
                      : undefined
                  }
                  trailingTone="success"
                  onPress={() => router.push(`/child/${childId}/report-card` as never)}
                />
              ))}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
