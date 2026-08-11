import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Card,
  Chip,
  EmptyState,
  ListRow,
  Screen,
  ScreenState,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, formatMoney, useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';

/**
 * `mobile/payment-history-receipts.png` — rows 16 and 17.
 *
 * Deliberately NOT child-scoped: row 16 ("payments made by this guardian") is
 * granted on any valid link without naming a child, so a parent who receives
 * no invoices still has a record of their own money. Each row names its child
 * so a parent with three of them can tell which is which.
 *
 * `is_own` is a DISPLAY label, not an access decision - `payments` has no
 * `payer_guardian_id` column yet, so the server matches best-effort on phone
 * number and always takes the safe side of that approximation.
 */
export default function PaymentHistoryReceipts(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.payments(), []);

  return (
    <Screen
      header={<AppHeader title={t('fees.receipts')} onBack={router.back} />}
      nav={<BottomNav items={navItems(t)} active="payments" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.length === 0 ? (
            <Card>
              <EmptyState title={t('common.notAvailable')} />
            </Card>
          ) : (
            <Card>
              {state.data.data.map((payment) => (
                <ListRow
                  key={payment.id}
                  title={payment.receipt_no}
                  subtitle={`${formatDate(payment.paid_on, language)}  •  ${payment.payment_method}`}
                  trailing={formatMoney(payment.amount, payment.currency, language)}
                  onPress={
                    payment.can_download_receipt
                      ? () =>
                          router.push(
                            `/child/${payment.student_id}/receipt/${payment.id}` as never,
                          )
                      : undefined
                  }
                />
              ))}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
