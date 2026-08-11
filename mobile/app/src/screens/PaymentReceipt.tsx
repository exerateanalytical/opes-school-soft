import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  DetailRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/payment-receipt.png` and `mobile/official-fees-receipt.png` — row 15.
 *
 * A verification DESCRIPTOR, not a PDF, and the reason is worth stating where
 * a reader will meet it: the only path to a signed document in this platform
 * is `RenderDocument`, which authorizes on `documents.print` - a staff
 * permission a guardian must never hold. Forking it to serve parents would put
 * a second, weaker path to a signed financial document into the product.
 *
 * What the parent gets instead is what the school actually honours at the
 * counter: the receipt number and a verification URL rendered as a code.
 */
export default function PaymentReceipt(): React.JSX.Element {
  const { t } = useI18n();
  const params = useLocalSearchParams<{ id?: string; payment?: string }>();
  const childId = Number(params.id ?? 0);
  const paymentId = Number(params.payment ?? 0);

  const state = useScreenData(
    async () => ({
      data: await me.receipt(childId, paymentId),
      stale: false,
      fetchedAt: Date.now(),
    }),
    [childId, paymentId],
  );

  return (
    <Screen header={<AppHeader title={t('fees.receipt')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const receipt = state.data.data as Record<string, unknown>;

            return (
              <>
                <Card>
                  <SectionCap>{t('fees.receipt')}</SectionCap>
                  {Object.entries(receipt)
                    .filter(([key]) => key !== 'verify_url' && key !== 'id')
                    .map(([key, value]) => (
                      <DetailRow
                        key={key}
                        label={key.replaceAll('_', ' ')}
                        value={value === null ? '—' : String(value)}
                      />
                    ))}
                </Card>

                <Card>
                  <SectionCap>{t('fees.verificationCode')}</SectionCap>
                  <View style={styles.codeBlock}>
                    <Text style={styles.code}>{String(receipt.receipt_no ?? '—')}</Text>
                  </View>
                  <Spacer size={8} />
                  <Muted>{t('documents.verifyHint')}</Muted>
                </Card>
              </>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  codeBlock: {
    borderWidth: 2,
    borderColor: colors.gold,
    borderRadius: radius.md,
    padding: spacing.lg,
    alignItems: 'center',
    backgroundColor: colors.goldSoft,
  },
  code: {
    fontSize: type.h3,
    fontWeight: weight.bold,
    color: colors.primary,
    letterSpacing: 2,
  },
});
