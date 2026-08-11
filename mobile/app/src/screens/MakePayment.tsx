import React, { useState } from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Button, Card, EmptyState, Muted, Screen, SectionCap, Spacer } from '@/components';
import { writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { enqueue } from '@/storage/outbox';
import { useI18n } from '@/i18n';

/**
 * `mobile/make-payment.png` — row 18.
 *
 * This screen exists and is wired to the REAL endpoint, which answers `501
 * not_implemented` because no payment gateway exists yet (spec §1 non-goals).
 * That is the point: the flow ships against the true contract and shows the
 * true message - "pay at the school office" - rather than against a mock that
 * would have to be torn out, and that would meanwhile teach parents the app
 * can take their money.
 *
 * A guardian who is not the fee payer never gets here: the server answers 403
 * before 501, and the tile is hidden by the capability projection anyway.
 */
export default function MakePayment(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function attempt(): Promise<void> {
    setBusy(true);
    setMessage(null);

    try {
      const queued = await enqueue({
        label: t('fees.makePayment'),
        path: `/me/children/${childId}/payments`,
        method: 'POST',
      });

      await writes.initiatePayment(childId, queued.idempotencyKey);
    } catch (error) {
      setMessage(
        error instanceof ApiError && error.code === 'not_implemented'
          ? error.message
          : error instanceof ApiError
            ? error.message
            : t('fees.notImplemented'),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen header={<AppHeader title={t('fees.makePayment')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('fees.paymentMethod')}</SectionCap>
        <Muted>{t('fees.notImplemented')}</Muted>
        <Spacer />
        <Button label={t('fees.payNow')} onPress={attempt} busy={busy} />
      </Card>

      {message ? (
        <Card>
          <EmptyState title={message} />
        </Card>
      ) : null}
    </Screen>
  );
}
