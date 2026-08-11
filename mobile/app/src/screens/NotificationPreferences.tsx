import React, { useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import { AppHeader, Button, Card, Muted, Screen, SectionCap, Spacer } from '@/components';
import { writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/notification-preferences.png`.
 *
 * Only the three CHANNEL switches are real - `notify_sms`, `notify_email` and
 * `notify_push` are columns on the guardian row and are inside row 29's
 * allow-list, so they persist.
 *
 * Per-TYPE preferences and quiet hours are shown in the design but are
 * explicitly P1 (spec §7: "Notification preferences … are P1"). They are not
 * rendered as disabled switches, because a switch a parent can see and flick
 * implies it does something. Better to state the gap.
 */
export default function NotificationPreferences(): React.JSX.Element {
  const { t } = useI18n();
  const [channels, setChannels] = useState({ sms: true, email: true, push: true });
  const [busy, setBusy] = useState(false);

  async function save(): Promise<void> {
    setBusy(true);

    try {
      await writes.updateProfile({
        notify_sms: channels.sms,
        notify_email: channels.email,
        notify_push: channels.push,
      });
      Alert.alert(t('settings.changesSaved'));
    } catch (error) {
      Alert.alert(error instanceof ApiError ? error.message : t('common.retry'));
    } finally {
      setBusy(false);
    }
  }

  const rows = [
    { key: 'sms' as const, label: 'SMS' },
    { key: 'email' as const, label: 'Email' },
    { key: 'push' as const, label: 'Push' },
  ];

  return (
    <Screen header={<AppHeader title={t('settings.notifications')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('settings.notifications')}</SectionCap>

        {rows.map((row) => (
          <Pressable
            key={row.key}
            style={styles.row}
            onPress={() => setChannels((current) => ({ ...current, [row.key]: !current[row.key] }))}
          >
            <Text style={styles.label}>{row.label}</Text>
            <View style={[styles.track, channels[row.key] && styles.trackOn]}>
              <View style={[styles.knob, channels[row.key] && styles.knobOn]} />
            </View>
          </Pressable>
        ))}

        <Spacer />
        <Button label={t('common.save')} onPress={save} busy={busy} />
      </Card>

      <Card>
        <Muted>{t('common.notAvailable')}</Muted>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.md,
  },
  label: { fontSize: type.body, fontWeight: weight.medium, color: colors.ink },
  track: {
    width: 48,
    height: 28,
    borderRadius: radius.pill,
    backgroundColor: colors.borderStrong,
    padding: 3,
  },
  trackOn: { backgroundColor: colors.primary },
  knob: { width: 22, height: 22, borderRadius: 11, backgroundColor: colors.white },
  knobOn: { alignSelf: 'flex-end' },
});
