import React, { useState } from 'react';
import { Alert } from 'react-native';
import { router } from 'expo-router';

import {
  AppHeader,
  Button,
  Card,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { auth } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';

/**
 * `mobile/security.png`.
 *
 * The device list is the security feature that matters on a shared or lost
 * phone, and it is real: tokens are device-bound (`mobile:{platform}:{device_id}`)
 * and revocable one at a time or all at once.
 *
 * `logout-all` is offered prominently because it is the honest answer to "I
 * lost my phone" - it revokes every device token, including this one. It does
 * NOT touch a staff integration token the same user might hold, which is
 * enforced server-side and covered by a test.
 *
 * No password change: there is no endpoint for one in the P0 surface. Better an
 * absent control than one that leads nowhere.
 */
export default function Security(): React.JSX.Element {
  const { t } = useI18n();
  const { signOut } = useSession();
  const [busy, setBusy] = useState(false);

  const state = useScreenData(
    async () => ({ data: await auth.devices(), stale: false, fetchedAt: Date.now() }),
    [],
  );

  return (
    <Screen header={<AppHeader title={t('settings.security')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <Card>
            <SectionCap>{t('settings.devices')}</SectionCap>

            {state.data.data.length === 0 ? (
              <EmptyState title={t('common.notAvailable')} />
            ) : (
              state.data.data.map((entry, index) => {
                const device = entry as Record<string, unknown>;

                return (
                  <ListRow
                    key={index}
                    title={String(device.platform ?? '—')}
                    subtitle={String(device.last_used_at ?? device.created_at ?? '')}
                    trailing={device.is_current ? t('common.active') : undefined}
                    trailingTone="success"
                    onPress={
                      device.is_current
                        ? undefined
                        : async () => {
                            await auth.forgetDevice(Number(device.id));
                            state.reload();
                          }
                    }
                  />
                );
              })
            )}
          </Card>
        ) : null}
      </ScreenState>

      <Card>
        <Button
          label={t('auth.signOutAll')}
          variant="danger"
          busy={busy}
          onPress={() => {
            Alert.alert(t('auth.signOutAll'), undefined, [
              { text: t('common.cancel'), style: 'cancel' },
              {
                text: t('auth.signOutAll'),
                style: 'destructive',
                onPress: async () => {
                  setBusy(true);
                  await signOut(true);
                },
              },
            ]);
          }}
        />
        <Spacer size={8} />
        <Muted>{t('auth.secureTwo')}</Muted>
      </Card>
    </Screen>
  );
}
