import React from 'react';
import { Alert } from 'react-native';
import { router } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Button,
  Card,
  ListRow,
  Muted,
  Screen,
  SectionCap,
  Spacer,
} from '@/components';
import { useI18n, type Language } from '@/i18n';
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';
import { writes } from '@/api/endpoints';

/**
 * `mobile/account-settings.png` — the "More" tab.
 *
 * Changing the language writes BACK through `PATCH /v1/me/profile` (row 29) as
 * well as switching the UI. That is the point: `guardians.language` is what the
 * school's SMS and email templates read, so a parent who switches to French
 * here should get tomorrow's fee reminder in French too. A purely local toggle
 * would leave the app and the school disagreeing about who this parent is.
 */
export default function AccountSettings(): React.JSX.Element {
  const { t, language, setLanguage } = useI18n();
  const { guardian, signOut } = useSession();

  async function changeLanguage(next: Language): Promise<void> {
    setLanguage(next);

    try {
      await writes.updateProfile({ language: next });
    } catch {
      // The UI already switched; the server copy will catch up on the next
      // successful profile write. Failing loudly here would be worse than the
      // brief divergence.
    }
  }

  return (
    <Screen
      header={<AppHeader title={t('settings.account')} subtitle={guardian?.display_name} />}
      nav={<BottomNav items={navItems(t)} active="more" onSelect={goTo} />}
    >
      <Card>
        <SectionCap>{t('settings.profile')}</SectionCap>
        <ListRow title={t('settings.profile')} onPress={() => router.push('/settings/profile' as never)} />
        <ListRow
          title={t('settings.notifications')}
          onPress={() => router.push('/settings/notifications' as never)}
        />
        <ListRow title={t('settings.security')} onPress={() => router.push('/settings/security' as never)} />
        <ListRow title={t('settings.help')} onPress={() => router.push('/settings/help' as never)} />
      </Card>

      <Card>
        <SectionCap>{t('settings.language')}</SectionCap>
        <ListRow
          title={t('settings.english')}
          trailing={language === 'en' ? '✓' : undefined}
          onPress={() => void changeLanguage('en')}
        />
        <ListRow
          title={t('settings.french')}
          trailing={language === 'fr' ? '✓' : undefined}
          onPress={() => void changeLanguage('fr')}
        />
      </Card>

      <Card>
        <SectionCap>{t('common.appName')}</SectionCap>
        <ListRow title={t('comms.announcements')} onPress={() => router.push('/announcements' as never)} />
        <ListRow title={t('activities.school')} onPress={() => router.push('/activities' as never)} />
        <ListRow title={t('common.search')} onPress={() => router.push('/search' as never)} />
      </Card>

      <Card>
        <Button
          label={t('auth.signOut')}
          variant="secondary"
          onPress={() => {
            Alert.alert(t('auth.signOut'), undefined, [
              { text: t('common.cancel'), style: 'cancel' },
              { text: t('auth.signOut'), style: 'destructive', onPress: () => void signOut() },
            ]);
          }}
        />
        <Spacer size={8} />
        <Muted>{t('settings.schoolManaged')}</Muted>
      </Card>
    </Screen>
  );
}
