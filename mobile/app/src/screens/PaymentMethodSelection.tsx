import React, { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Button, Card, Muted, Screen, SectionCap, Spacer } from '@/components';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/payment-method-selection.png`.
 *
 * The methods are listed because the design shows them and because the flow
 * has to exist for the day a gateway lands - but none of them is wired, and
 * the screen says so before a parent picks one rather than after. There is no
 * gateway behind `POST /me/children/{s}/payments`; it answers 501 by design.
 *
 * Showing a live-looking method picker that fails at the last step would be
 * the worst version of this screen: a parent would believe they had paid.
 */
export default function PaymentMethodSelection(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const [method, setMethod] = useState('mtn');

  const methods = [
    { key: 'mtn', label: 'MTN Mobile Money' },
    { key: 'orange', label: 'Orange Money' },
    { key: 'card', label: 'Card' },
    { key: 'bank', label: 'Bank transfer' },
  ];

  return (
    <Screen header={<AppHeader title={t('fees.paymentMethod')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('fees.paymentMethod')}</SectionCap>

        {methods.map((entry) => (
          <Pressable
            key={entry.key}
            onPress={() => setMethod(entry.key)}
            style={[styles.method, method === entry.key && styles.methodOn]}
          >
            <View style={[styles.radio, method === entry.key && styles.radioOn]} />
            <Text style={styles.methodLabel}>{entry.label}</Text>
          </Pressable>
        ))}

        <Spacer />
        <Muted>{t('fees.notImplemented')}</Muted>
        <Spacer size={8} />
        <Button
          label={t('fees.payNow')}
          onPress={() => router.push(`/child/${childId}/pay` as never)}
        />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  method: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
  },
  methodOn: { borderColor: colors.primary, backgroundColor: colors.primarySoft },
  radio: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: colors.borderStrong,
  },
  radioOn: { borderColor: colors.primary, borderWidth: 6 },
  methodLabel: { fontSize: type.body, fontWeight: weight.medium, color: colors.ink },
});
