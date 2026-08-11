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
import { useI18n } from '@/i18n';

/**
 * `mobile/medical-history.png` — row 4, the FULL medical scope.
 *
 * A guardian holding only row 3 (emergency) gets 403 here and sees the
 * "not shared" state, which is correct and not a failure: the emergency scope
 * exists so a school can give a non-custodial contact a blood group and an
 * allergy without handing over the child's clinical history.
 *
 * Never cached to disk. See HealthOverview.
 */
export default function MedicalHistory(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);

  const state = useScreenData(
    async () => ({ data: await me.medical(childId), stale: false, fetchedAt: Date.now() }),
    [childId],
  );

  return (
    <Screen header={<AppHeader title={t('health.medicalHistory')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const record = state.data.data as Record<string, unknown>;

            if (String(record.scope ?? 'emergency') !== 'full') {
              return (
                <Card>
                  <EmptyState tone="denied" title={t('common.noAccess')} body={t('health.emergencyOnly')} />
                </Card>
              );
            }

            const entries = Object.entries(record).filter(([key]) => key !== 'scope');

            return (
              <Card>
                <SectionCap>{t('health.medicalHistory')}</SectionCap>
                {entries.length === 0 ? (
                  <Muted>{t('common.notAvailable')}</Muted>
                ) : (
                  entries.map(([key, value]) => (
                    <DetailRow
                      key={key}
                      label={key.replaceAll('_', ' ')}
                      value={value === null || value === '' ? '—' : String(value)}
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
