import React, { useState } from 'react';
import { Alert } from 'react-native';
import { router } from 'expo-router';

import { AppHeader, Button, Card, Field, Muted, Screen, SectionCap, Spacer } from '@/components';
import { writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';

/**
 * `mobile/parent-profile.png` — row 29, the ONLY place a parent writes about
 * themselves.
 *
 * The fields offered here are exactly the server's allow-list. The
 * authorization flags (`has_custody`, `receives_reports`, …) are absent by
 * design and not merely disabled: row 30 grants them to nobody, because a
 * parent who could edit their own flags could grant themselves every other row
 * in the matrix. The server treats an attempt as a security event and audits
 * it; this screen simply never makes one.
 */
export default function ParentProfile(): React.JSX.Element {
  const { t } = useI18n();
  const { guardian, refresh } = useSession();

  const [phone, setPhone] = useState(guardian?.phone ?? '');
  const [email, setEmail] = useState(guardian?.email ?? '');
  const [city, setCity] = useState('');
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  async function save(): Promise<void> {
    setBusy(true);
    setErrors({});

    try {
      await writes.updateProfile({ phone, email, city });
      await refresh();
      Alert.alert(t('settings.changesSaved'));
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.details);
        if (error.isDenied) Alert.alert(t('settings.schoolManaged'));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen header={<AppHeader title={t('settings.profile')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('settings.profile')}</SectionCap>

        <Field
          label={t('auth.identifier')}
          value={guardian?.display_name ?? ''}
          onChangeText={() => undefined}
          editable={false}
          hint={t('settings.schoolManaged')}
        />
        <Spacer size={8} />
        <Field label="Phone" value={phone} onChangeText={setPhone} keyboardType="phone-pad" error={errors.phone?.[0]} />
        <Spacer size={8} />
        <Field label="Email" value={email} onChangeText={setEmail} keyboardType="email-address" error={errors.email?.[0]} />
        <Spacer size={8} />
        <Field label="City" value={city} onChangeText={setCity} autoCapitalize="words" />

        <Spacer />
        <Button label={t('common.save')} onPress={save} busy={busy} />
      </Card>

      <Card>
        <Muted>{t('settings.schoolManaged')}</Muted>
      </Card>
    </Screen>
  );
}
