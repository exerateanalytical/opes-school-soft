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
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/official-fees-receipt.png` — the letterheaded version of the receipt.
 *
 * Still a descriptor, not a PDF, for the reason recorded in PaymentReceipt: the
 * only path to a signed financial document is gated on `documents.print`, a
 * staff permission. What makes this the "official" view is the letterhead and
 * the verification block, which is what the front desk actually checks - and
 * that block is genuine, because the code resolves at the public verify page.
 *
 * It carries no "DUPLICATA"/"SPECIMEN" overlay, and must not invent one: those
 * overlays are applied by RenderDocument from the print log and the fiscal
 * identity gate, and a client-side imitation would be a forgery of a control.
 */
export default function OfficialFeesReceipt(): React.JSX.Element {
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
              <Card padded={false}>
                <View style={styles.letterhead}>
                  <Text style={styles.school}>HERITAGE BILINGUAL COLLEGE</Text>
                  <Text style={styles.motto}>EXCELLENCE IN EDUCATION</Text>
                  <Text style={styles.docTitle}>REÇU OFFICIEL / OFFICIAL RECEIPT</Text>
                </View>

                <View style={styles.sheet}>
                  <DetailRow label="N°" value={String(receipt.receipt_no ?? '—')} />
                  <DetailRow label={t('fees.paid')} value={String(receipt.paid_on ?? '—')} />
                  <DetailRow
                    label={t('fees.amountPaid')}
                    value={`${String(receipt.amount ?? '—')} ${String(receipt.currency ?? '')}`}
                  />
                  <DetailRow
                    label={t('fees.paymentMethod')}
                    value={String(receipt.payment_method ?? '—')}
                  />
                  <DetailRow label="Payer" value={String(receipt.payer_name ?? '—')} />

                  <Spacer />
                  <View style={styles.verifyBlock}>
                    <Text style={styles.verifyLabel}>{t('fees.verificationCode')}</Text>
                    <Text style={styles.verifyCode}>{String(receipt.receipt_no ?? '—')}</Text>
                  </View>
                  <Spacer size={8} />
                  <Muted>{t('documents.verifyHint')}</Muted>
                </View>
              </Card>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  letterhead: {
    backgroundColor: colors.primary,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.lg,
    alignItems: 'center',
    gap: 2,
  },
  school: { fontSize: type.bodyLg, fontWeight: weight.bold, color: colors.onPrimary, letterSpacing: 2 },
  motto: { fontSize: type.caption, color: colors.gold, letterSpacing: 1 },
  docTitle: { fontSize: type.small, color: colors.onPrimaryMuted, marginTop: spacing.sm },
  sheet: { padding: spacing.lg },
  verifyBlock: {
    borderWidth: 2,
    borderColor: colors.gold,
    backgroundColor: colors.goldSoft,
    borderRadius: radius.md,
    padding: spacing.lg,
    alignItems: 'center',
    gap: spacing.xs,
  },
  verifyLabel: { fontSize: type.label, color: colors.inkMuted, letterSpacing: 1 },
  verifyCode: { fontSize: type.h3, fontWeight: weight.bold, color: colors.primary, letterSpacing: 2 },
});
