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
 * `mobile/health-overview.png` — rows 3 and 4.
 *
 * Two scopes: emergency-only (row 3) and full (row 4). Never cached to disk -
 * `me.medical()` deliberately bypasses the read cache, because the server
 * sends `Cache-Control: private, no-store` on it and honouring that on the
 * server while writing the body to AsyncStorage would make the header theatre.
 */
export default function HealthOverview(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);

  const state = useScreenData(
    async () => ({ data: await me.medical(childId), stale: false, fetchedAt: Date.now() }),
    [childId],
  );

  return (
    <Screen header={<AppHeader title={t('health.overview')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const record = state.data.data as Record<string, unknown>;
            const scope = String(record.scope ?? 'emergency');
            const entries = Object.entries(record).filter(([key]) => key !== 'scope');

            return (
              <>
                {scope === 'emergency' ? (
                  <Card>
                    <Muted>{t('health.emergencyOnly')}</Muted>
                  </Card>
                ) : null}

                <Card>
                  <SectionCap>{t('health.overview')}</SectionCap>
                  {entries.length === 0 ? (
                    <EmptyState title={t('common.notAvailable')} />
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
              </>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}
