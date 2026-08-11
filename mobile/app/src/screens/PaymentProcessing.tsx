import React, { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Button, Card, EmptyState, Muted, Screen, Spacer } from '@/components';
import { writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { enqueue } from '@/storage/outbox';
import { useI18n } from '@/i18n';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/payment-processing.png`.
 *
 * A real attempt against the real endpoint, which answers 501. The spinner is
 * therefore brief and honest - it is showing an actual request in flight, not
 * a staged delay - and it resolves to the school's own message.
 *
 * The idempotency key is stamped when the attempt is QUEUED, so that when a
 * gateway does land, a parent who loses signal mid-payment and retries cannot
 * be charged twice. Building that in now costs nothing and is impossible to
 * retrofit safely once money is moving.
 */
export default function PaymentProcessing(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const [outcome, setOutcome] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const queued = await enqueue({
          label: t('fees.makePayment'),
          path: `/me/children/${childId}/payments`,
          method: 'POST',
        });

        await writes.initiatePayment(childId, queued.idempotencyKey);
        if (!cancelled) setOutcome(t('common.notAvailable'));
      } catch (error) {
        if (cancelled) return;

        setOutcome(error instanceof ApiError ? error.message : t('fees.notImplemented'));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [childId, t]);

  return (
    <Screen header={<AppHeader title={t('fees.makePayment')} onBack={router.back} />}>
      <Card>
        {outcome === null ? (
          <View style={styles.centre}>
            <ActivityIndicator color={colors.primary} size="large" />
            <Spacer />
            <Text style={styles.label}>{t('fees.processing')}</Text>
          </View>
        ) : (
          <>
            <EmptyState title={outcome} />
            <Muted>{t('fees.notImplemented')}</Muted>
            <Spacer />
            <Button label={t('common.close')} variant="secondary" onPress={router.back} />
          </>
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  centre: { alignItems: 'center', paddingVertical: spacing.xl },
  label: { fontSize: type.bodyLg, fontWeight: weight.medium, color: colors.inkMuted },
});
