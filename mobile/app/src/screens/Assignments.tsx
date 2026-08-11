import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';

/**
 * `mobile/assignments.png`.
 *
 * Homework is real in the platform (there is in-flight `Assessment\Homework`
 * work) but it has NO guardian endpoint in the P0 API - it is not one of the
 * 22 documented operations, and there is no matrix row granting it. So this
 * screen shows the child's timetable, which is what the app can truthfully
 * answer today about "what does my child have on", and states the gap.
 *
 * The alternative - inventing a client-side homework list, or calling a staff
 * endpoint - would either be fiction or a hole. Wiring this properly is a
 * P1 API decision (a new row and a new operation), not something this screen
 * may improvise.
 */
export default function Assignments(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.timetable(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('academics.assignments')} onBack={router.back} />}>
      <Card>
        <Muted>{t('common.notAvailable')}</Muted>
      </Card>

      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <Card>
            <SectionCap>{t('academics.timetable')}</SectionCap>

            {state.data.data.slots.length === 0 ? (
              <EmptyState title={t('common.notAvailable')} />
            ) : (
              state.data.data.slots.map((slot, index) => (
                <ListRow
                  key={index}
                  title={slot.subject_name ?? slot.period_name}
                  subtitle={`${slot.starts_at} – ${slot.ends_at}`}
                  trailing={slot.room_name ?? undefined}
                />
              ))
            )}
          </Card>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
