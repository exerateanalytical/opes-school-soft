import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ProgressBar,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';

/**
 * `mobile/subject-results.png`.
 *
 * Marks come straight from the stored report-card SNAPSHOT and are never
 * recomputed on the client (01-assessment 13.3). A client that averaged the
 * subject rows itself would sooner or later disagree with the printed bulletin,
 * and the printed bulletin is the document a parent can take to the office.
 */
export default function SubjectResults(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.results(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.bySubject')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.periods.length === 0 ? (
            <Card>
              <EmptyState title={t('academics.notPublished')} />
            </Card>
          ) : (
            state.data.data.periods.map((period) => {
              const subjects = (period.payload.subjects ?? []) as {
                name?: string;
                average?: number;
                grade?: string;
              }[];

              return (
                <Card key={period.snapshot_id}>
                  <SectionCap>{period.period.name}</SectionCap>

                  {subjects.length === 0 ? (
                    <EmptyState title={t('common.notAvailable')} />
                  ) : (
                    subjects.map((subject, index) => (
                      <Row key={`${subject.name}-${index}`} gap={12}>
                        <ProgressBar value={subject.average ?? 0} />
                      </Row>
                    ))
                  )}
                </Card>
              );
            })
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
