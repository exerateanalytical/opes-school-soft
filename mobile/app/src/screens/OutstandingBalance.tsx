import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  Chip,
  EmptyState,
  ListRow,
  ProgressRing,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  StatTile,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, formatMoney, useI18n } from '@/i18n';

/**
 * `mobile/outstanding-balance.png`.
 *
 * The outstanding figure is the statement's CLOSING BALANCE, not a separate
 * sum - the server derives it that way precisely so a parent's headline number
 * and their line list can never disagree. This screen therefore shows both on
 * one page without recomputing anything.
 */
export default function OutstandingBalance(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.fees(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('fees.outstanding')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const fees = state.data.data;
            const pct = fees.totals.billed > 0 ? Math.round((fees.totals.paid / fees.totals.billed) * 100) : 0;

            return (
              <>
                <Card>
                  <Row gap={24}>
                    <ProgressRing value={pct} label={`${pct}%`} caption={t('fees.paid')} />
                    <StatTile
                      tone="danger"
                      label={t('fees.balanceDue')}
                      value={formatMoney(fees.totals.outstanding, fees.currency, language)}
                      caption={
                        fees.totals.next_due_on
                          ? t('fees.dueDate', { date: formatDate(fees.totals.next_due_on, language) })
                          : undefined
                      }
                    />
                  </Row>
                </Card>

                <Card>
                  <SectionCap>{t('fees.statement')}</SectionCap>
                  {fees.statement.length === 0 ? (
                    <EmptyState title={t('common.noAccess')} tone="denied" />
                  ) : (
                    fees.statement.map((line, index) => (
                      <ListRow
                        key={`${line.reference}-${index}`}
                        title={line.description}
                        subtitle={`${formatDate(line.date, language)}  •  ${line.reference}`}
                        trailing={formatMoney(line.balance, fees.currency, language)}
                      />
                    ))
                  )}
                </Card>
              </>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}
