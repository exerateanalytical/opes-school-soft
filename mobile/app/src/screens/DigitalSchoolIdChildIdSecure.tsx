import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Button, Card, Muted, Screen, Spacer } from '@/components';
import DigitalSchoolIdChildId from './DigitalSchoolIdChildId';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/digital-school-id-child-id-secure.png` — the same card behind a
 * reveal.
 *
 * The gate is honest about what it is: a shoulder-surfing guard, not a security
 * control. It stops a child's photo, name and matricule sitting face-up when a
 * parent hands their phone over or opens the app on a bus. The real control is
 * the device token and the matrix behind it - anyone holding this unlocked
 * phone could reach the same facts through the child overview, so pretending
 * this is authentication would be a lie about the threat model.
 *
 * Deliberately NOT wired to biometrics: `expo-local-authentication` is not a
 * dependency of this build, and adding a permission prompt for a protection
 * this weak is a poor trade.
 */
export default function DigitalSchoolIdChildIdSecure(): React.JSX.Element {
  const { t } = useI18n();
  const [revealed, setRevealed] = useState(false);
  useLocalSearchParams<{ id?: string }>();

  if (revealed) return <DigitalSchoolIdChildId />;

  return (
    <Screen header={<AppHeader title={t('settings.security')} onBack={router.back} />}>
      <Card>
        <View style={styles.shield}>
          <Text style={styles.shieldGlyph}>🔒</Text>
        </View>

        <Spacer />
        <Text style={styles.title}>{t('common.appName')}</Text>
        <Spacer size={8} />
        <Muted>{t('auth.secureTwo')}</Muted>

        <Spacer />
        <Button label={t('common.verify')} onPress={() => setRevealed(true)} />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  shield: {
    alignSelf: 'center',
    width: 88,
    height: 88,
    borderRadius: radius.pill,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  shieldGlyph: { fontSize: 36 },
  title: {
    fontSize: type.h3,
    fontWeight: weight.bold,
    color: colors.ink,
    textAlign: 'center',
  },
});
