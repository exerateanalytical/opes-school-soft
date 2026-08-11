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
import { formatDate, useI18n } from '@/i18n';

/**
 * `mobile/immunization-vaccination-records.png` — part of row 4's full scope.
 *
 * The medical payload's `immunisations` key when the server sends one. Absent
 * for an emergency-scope link, which is the denial, not a gap.
 */
export default function ImmunizationVaccinationRecords(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);

  const state = useScreenData(
    async () => ({ data: await me.medical(childId), stale: false, fetchedAt: Date.now() }),
    [childId],
  );

  return (
    <Screen header={<AppHeader title={t('health.immunization')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const record = state.data.data as Record<string, unknown>;
            const shots = (record.immunisations ?? record.immunizations ?? []) as Record<
              string,
              unknown
            >[];

            if (String(record.scope ?? 'emergency') !== 'full') {
              return (
                <Card>
                  <EmptyState tone="denied" title={t('common.noAccess')} body={t('health.emergencyOnly')} />
                </Card>
              );
            }

            return (
              <Card>
                <SectionCap>{t('health.immunization')}</SectionCap>

                {shots.length === 0 ? (
                  <EmptyState title={t('common.notAvailable')} />
                ) : (
                  shots.map((shot, index) => (
                    <ListRow
                      key={index}
                      title={String(shot.vaccine ?? shot.name ?? '—')}
                      subtitle={formatDate(String(shot.administered_on ?? ''), language)}
                      trailing={shot.dose ? String(shot.dose) : undefined}
                      trailingTone="success"
                    />
                  ))
                )}
              </Card>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}
