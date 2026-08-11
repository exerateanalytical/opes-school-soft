import React from 'react';
import { Linking } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Avatar,
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
 * `mobile/emergency-important-contacts.png` — row 31.
 *
 * The other guardians of this child, and the server sends **names and
 * relationship only**. No phone number, no email, no ID number: row 31 grants
 * a parent the right to know who else is on their child's record, not the
 * right to a directory of the other family. That narrowing happens server-side
 * and this screen must not paper over it by offering a call button that would
 * have nothing to dial.
 *
 * The school's own numbers are dialable, because those are public.
 */
export default function EmergencyImportantContacts(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.otherGuardians(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('health.emergencyContact')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card>
              <SectionCap>{t('health.emergencyContact')}</SectionCap>

              {state.data.data.length === 0 ? (
                <EmptyState title={t('common.notAvailable')} />
              ) : (
                state.data.data.map((entry, index) => {
                  const row = entry as Record<string, unknown>;
                  const name = String(row.display_name ?? row.name ?? '—');

                  return (
                    <ListRow
                      key={index}
                      leading={<Avatar name={name} />}
                      title={name}
                      subtitle={String(row.relationship ?? '')}
                    />
                  );
                })
              )}
            </Card>

            <Card>
              <Muted>{t('common.noAccess')}</Muted>
            </Card>

            <Card>
              <SectionCap>{t('common.appName')}</SectionCap>
              <ListRow
                title={t('comms.contactTeacher')}
                subtitle={t('comms.classTeacher')}
                onPress={() => router.push('/(tabs)/messages' as never)}
              />
              <ListRow
                title={t('settings.help')}
                onPress={() => void Linking.openURL('tel:+237000000000')}
              />
            </Card>
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
