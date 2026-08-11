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

/**
 * The child's class timetable — row 26.
 *
 * The one screen here with NO reference PNG. It exists because `ChildOverview`
 * offers a timetable tile (row 26 is granted on any valid link, so almost every
 * parent sees it) and the endpoint has shipped since Slice C. A tile that
 * navigated nowhere would be a worse answer to the missing design than a plain
 * screen built from the same kit.
 *
 * Slots are the ones effective on the request's business date, which the server
 * fixes once per request (7.3) so a timetable loaded across midnight cannot
 * show two different days.
 */
export default function ClassTimetable(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.timetable(childId), [childId]);

  const days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  return (
    <Screen header={<AppHeader title={t('academics.timetable')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.slots.length === 0 ? (
            <Card>
              <EmptyState title={t('common.notAvailable')} />
            </Card>
          ) : (
            [1, 2, 3, 4, 5, 6, 7]
              .map((day) => ({
                day,
                slots: state.data.data.slots.filter((slot) => slot.day_of_week === day),
              }))
              .filter((group) => group.slots.length > 0)
              .map((group) => (
                <Card key={group.day}>
                  <SectionCap>{days[group.day] ?? String(group.day)}</SectionCap>

                  {group.slots.map((slot, index) => (
                    <ListRow
                      key={index}
                      title={slot.subject_name ?? slot.period_name}
                      subtitle={`${slot.starts_at} – ${slot.ends_at}`}
                      trailing={slot.room_name ?? undefined}
                    />
                  ))}
                </Card>
              ))
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
