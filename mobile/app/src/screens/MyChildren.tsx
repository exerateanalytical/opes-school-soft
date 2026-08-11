import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  Avatar,
  BottomNav,
  Card,
  Chip,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';

/**
 * `mobile/my-children.png`.
 *
 * Row 1 of the matrix is the floor: every currently-valid link puts its child
 * here, however narrow the link's other flags are. So this list is never
 * empty for a linked parent — and if it IS empty, that is a real answer (the
 * links have expired), not a loading failure.
 */
export default function MyChildren(): React.JSX.Element {
  const { t } = useI18n();
  const { selectChild } = useSession();
  const state = useScreenData(() => me.children(), []);

  return (
    <Screen
      header={<AppHeader title={t('dashboard.myChildren')} onBell={() => router.push('/notifications' as never)} />}
      nav={<BottomNav items={navItems(t)} active="children" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.length === 0 ? (
            <EmptyState tone="denied" title={t('common.noAccess')} />
          ) : (
            <Card>
              {state.data.data.map((child) => (
                <ListRow
                  key={child.id}
                  leading={<Avatar name={child.display_name} />}
                  title={child.display_name}
                  subtitle={[child.class, child.matricule].filter(Boolean).join('  •  ')}
                  trailing={t('common.active')}
                  trailingTone="success"
                  onPress={() => {
                    selectChild(child.id);
                    router.push(`/child/${child.id}` as never);
                  }}
                />
              ))}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
