import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  BottomNav,
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
import { formatDate, useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';

/**
 * `mobile/school-activities.png`.
 *
 * Activities and events are an explicit NON-GOAL of the P0 API (spec §1) -
 * there is no `activities` table exposed to guardians and no matrix row for
 * one. What the school genuinely broadcasts today is announcements (row 26),
 * so this screen shows those and does not pretend to a calendar it cannot
 * populate.
 *
 * Wiring real activities is a P1 API decision: a new capability row, an
 * endpoint, and a per-child scoping rule. Not something a client may invent.
 */
export default function SchoolActivities(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.announcements(), []);

  return (
    <Screen
      header={<AppHeader title={t('activities.school')} onBack={router.back} />}
      nav={<BottomNav items={navItems(t)} active="more" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card>
              <SectionCap>{t('comms.announcements')}</SectionCap>

              {state.data.data.length === 0 ? (
                <EmptyState title={t('common.notAvailable')} />
              ) : (
                state.data.data.map((entry) => (
                  <ListRow
                    key={entry.id}
                    title={entry.title}
                    subtitle={formatDate(entry.published_at, language)}
                    unread={!entry.is_read}
                    onPress={() => router.push(`/activity/${entry.id}` as never)}
                  />
                ))
              )}
            </Card>

            <Card>
              <Muted>{t('common.notAvailable')}</Muted>
            </Card>
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
