import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';

/** `mobile/academic-overview.png` — the term/period index for one child. */
export default function AcademicOverview(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.resultsOverview')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <Card>
            <SectionCap>{t('academics.termSummary')}</SectionCap>
            {state.data.data.periods.length === 0 ? (
              <EmptyState title={t('academics.notPublished')} />
            ) : (
              state.data.data.periods.map((period) => (
                <ListRow
                  key={period.snapshot_id}
                  title={
                    language === 'fr' && period.period.name_fr
                      ? period.period.name_fr
                      : period.period.name
                  }
                  subtitle={period.issued_at ?? undefined}
                  onPress={() =>
                    router.push(`/child/${childId}/period/${period.period.id}` as never)
                  }
                />
              ))
            )}
          </Card>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
