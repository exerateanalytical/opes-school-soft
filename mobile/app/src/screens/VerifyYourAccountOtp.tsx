import React, { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import { AppHeader, Button, Card, Muted, Screen, Spacer } from '@/components';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/verify-your-account-otp.png`.
 *
 * Present in the design set, but 2FA/OTP is an explicit NON-GOAL of the P0 API
 * (spec §1) - there is no endpoint to verify a code against. The screen is
 * built to the design and wired to nothing, and says so here rather than
 * pretending: shipping it against a fake verifier would teach parents a
 * security step that does not exist.
 */
export default function VerifyYourAccountOtp(): React.JSX.Element {
  const { t } = useI18n();
  const [digits, setDigits] = useState<string[]>(['', '', '', '', '', '']);

  return (
    <Screen header={<AppHeader title={t('auth.otpTitle')} subtitle={t('auth.otpSubtitle')} onBack={router.back} />}>
      <Card>
        <View style={styles.row}>
          {digits.map((digit, index) => (
            <Pressable
              key={index}
              style={[styles.cell, digit !== '' && styles.cellFilled]}
              onPress={() =>
                setDigits((current) => current.map((value, i) => (i === index ? '0' : value)))
              }
            >
              <Text style={styles.cellText}>{digit}</Text>
            </Pressable>
          ))}
        </View>

        <Spacer />
        <Button label={t('common.submit')} disabled />
        <Spacer size={8} />
        <Muted>{t('auth.resendCode')}</Muted>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: spacing.sm, justifyContent: 'space-between' },
  cell: {
    flex: 1,
    height: 56,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.white,
    alignItems: 'center',
    justifyContent: 'center',
  },
  cellFilled: { borderColor: colors.primary },
  cellText: { fontSize: type.h3, fontWeight: weight.bold, color: colors.ink },
});
