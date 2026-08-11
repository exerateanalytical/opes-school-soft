import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Card,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';

/**
 * `mobile/school-announcements.png` — row 26.
 *
 * Granted on any valid link, so not child-scoped. A guardian whose every link
 * has expired gets 403 and sees the "not shared" state, which is the correct
 * reading of 7.5's historic-access rule: an announcement is about a school you
 * are currently part of.
 */
export default function SchoolAnnouncements(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.announcements(), []);

  return (
    <Screen
      header={<AppHeader title={t('comms.announcements')} onBack={router.back} />}
      nav={<BottomNav items={navItems(t)} active="more" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.length === 0 ? (
            <Card>
              <EmptyState title={t('comms.noMessages')} />
            </Card>
          ) : (
            <Card>
              {state.data.data.map((announcement) => (
                <ListRow
                  key={announcement.id}
                  title={announcement.title}
                  subtitle={announcement.body ?? formatDate(announcement.published_at, language)}
                  unread={!announcement.is_read}
                />
              ))}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
