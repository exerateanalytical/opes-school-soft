import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatMoney, useI18n } from '@/i18n';

/**
 * `mobile/fee-structure-breakdown.png`.
 *
 * This is what THIS CHILD was billed - the issued invoice lines grouped by
 * item - not the school's published price list. The distinction matters to a
 * parent whose child had a scholarship, a sibling discount or a mid-year
 * adjustment: the price list would show them a number they never owed.
 */
export default function FeeStructureBreakdown(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.fees(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('fees.structure')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <Card>
            <SectionCap>{t('fees.structure')}</SectionCap>

            {state.data.data.structure.length === 0 ? (
              <EmptyState title={t('common.notAvailable')} />
            ) : (
              <>
                {state.data.data.structure.map((line) => (
                  <ListRow
                    key={line.fee_item}
                    title={language === 'fr' && line.fee_item_fr ? line.fee_item_fr : line.fee_item}
                    subtitle={line.category_code ?? undefined}
                    trailing={formatMoney(line.amount, state.data.data.currency, language)}
                  />
                ))}
                <Spacer size={8} />
                <ListRow
                  title={t('fees.totalFees')}
                  trailing={formatMoney(
                    state.data.data.totals.billed,
                    state.data.data.currency,
                    language,
                  )}
                />
              </>
            )}
          </Card>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
