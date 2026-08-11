import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  Avatar,
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
 * `mobile/messages-inbox.png`.
 *
 * Threads are authorized by PARTICIPATION, not by the guardian matrix - the
 * server is explicit about this. So the inbox is not child-scoped and does not
 * disappear when a link expires mid-conversation, which is exactly the
 * behaviour a parent and a teacher both need.
 */
export default function MessagesInbox(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.threads(), []);

  return (
    <Screen
      header={<AppHeader title={t('comms.inbox')} onBell={() => router.push('/notifications' as never)} />}
      nav={<BottomNav items={navItems(t)} active="messages" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.length === 0 ? (
            <Card>
              <EmptyState title={t('comms.noMessages')} />
            </Card>
          ) : (
            <Card>
              {state.data.data.map((thread) => (
                <ListRow
                  key={thread.id}
                  leading={<Avatar name={thread.title} />}
                  title={thread.title}
                  subtitle={formatDate(thread.last_message_at, language)}
                  unread={thread.unread_count > 0}
                  trailing={thread.unread_count > 0 ? String(thread.unread_count) : undefined}
                  trailingTone="danger"
                  onPress={() => router.push(`/thread/${thread.id}` as never)}
                />
              ))}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
