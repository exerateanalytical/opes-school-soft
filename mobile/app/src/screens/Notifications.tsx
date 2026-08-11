import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  Button,
  Card,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  useScreenData,
} from '@/components';
import { me, writes } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';
import { followDeepLink } from '@/navigation';

/** `mobile/notifications.png` — own rows only; `user_id` IS the scope. */
export default function Notifications(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(
    async () => ({ data: (await me.notifications()).data, stale: false, fetchedAt: Date.now() }),
    [],
  );

  return (
    <Screen header={<AppHeader title={t('comms.notifications')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.length === 0 ? (
            <Card>
              <EmptyState title={t('comms.noMessages')} />
            </Card>
          ) : (
            <>
              <Button
                label={t('comms.markAllRead')}
                variant="secondary"
                onPress={async () => {
                  await writes.readAllNotifications();
                  state.reload();
                }}
              />
              <Card>
                {state.data.data.map((notification) => (
                  <ListRow
                    key={notification.id}
                    title={notification.title}
                    subtitle={notification.body ?? formatDate(notification.created_at, language)}
                    unread={notification.read_at === null}
                    onPress={async () => {
                      await writes.readNotification(notification.id);
                      if (notification.deep_link) followDeepLink(notification.deep_link);
                      else state.reload();
                    }}
                  />
                ))}
              </Card>
            </>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
