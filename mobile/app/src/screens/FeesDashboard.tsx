import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Button,
  Card,
  Chip,
  ChildContextCard,
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
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/fees-dashboard.png`.
 *
 * Every figure here is minor units from the wire, formatted once by
 * `formatMoney`. XAF has no centimes in practice, so the formatter does NOT
 * divide by 100 for it — dividing would invent a decimal place the franc does
 * not have and turn 850 000 FCFA into 8 500.
 *
 * The invoice list and the statement are gated separately by the server (rows
 * 13 and 14), so a parent may legitimately see one and not the other. Both
 * arrive as empty arrays in that case, which is why neither is rendered as an
 * error.
 */
export default function FeesDashboard(): React.JSX.Element {
  const { t, language } = useI18n();
  const params = useLocalSearchParams<{ id?: string }>();
  const childId = Number(params.id ?? 0);
  const { children, can } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;

  const state = useScreenData(() => me.fees(childId), [childId]);

  return (
    <Screen
      header={
        <AppHeader title={t('fees.dashboard')} subtitle={t('fees.subtitle')} onBack={router.back} unread={3} />
      }
      nav={<BottomNav items={navItems(t)} active="payments" onSelect={goTo} variant="dark" />}
    >
      {child ? (
        <ChildContextCard
          name={child.display_name}
          className={child.class}
          matricule={child.matricule}
          switchLabel={t('common.switchChild')}
          onSwitch={() => router.push('/(tabs)/children' as never)}
        />
      ) : null}

      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const fees = state.data.data;
            const paidPct =
              fees.totals.billed > 0
                ? Math.round((fees.totals.paid / fees.totals.billed) * 100)
                : 0;

            if (!fees.has_enrollment) {
              return (
                <Card>
                  <EmptyState title={t('common.notAvailable')} />
                </Card>
              );
            }

            return (
              <>
                <Card style={styles.summary}>
                  <Row>
                    <StatTile
                      onDark
                      label={t('fees.totalFees')}
                      value={formatMoney(fees.totals.billed, fees.currency, language)}
                    />
                    <StatTile
                      onDark
                      label={t('fees.amountPaid')}
                      value={formatMoney(fees.totals.paid, fees.currency, language)}
                    />
                    <StatTile
                      onDark
                      tone="danger"
                      label={t('fees.balanceDue')}
                      value={formatMoney(fees.totals.outstanding, fees.currency, language)}
                    />
                  </Row>
                </Card>

                <Card>
                  <SectionCap>{t('fees.feesOverview')}</SectionCap>
                  <Row gap={spacing.xl}>
                    <ProgressRing value={paidPct} label={`${paidPct}%`} caption={t('fees.paid')} />
                    <View style={styles.legend}>
                      <LegendRow
                        tone={colors.success}
                        label={t('fees.paid')}
                        value={formatMoney(fees.totals.paid, fees.currency, language)}
                      />
                      <LegendRow
                        tone={colors.danger}
                        label={t('fees.balanceDue')}
                        value={formatMoney(fees.totals.outstanding, fees.currency, language)}
                      />
                      <LegendRow
                        tone={colors.ink}
                        label={t('fees.totalFees')}
                        value={formatMoney(fees.totals.billed, fees.currency, language)}
                      />
                    </View>
                  </Row>
                </Card>

                {fees.installments.length > 0 ? (
                  <Card>
                    <SectionCap action={t('common.viewAll')}>{t('fees.dueFees')}</SectionCap>
                    {fees.installments.map((installment) => (
                      <ListRow
                        key={installment.id}
                        title={installment.label}
                        subtitle={t('fees.dueDate', { date: formatDate(installment.due_on, language) })}
                        trailing={formatMoney(installment.amount, fees.currency, language)}
                      />
                    ))}
                    {can('fees.payment.initiate', childId) ? (
                      <Button
                        label={t('fees.payNow')}
                        onPress={() => router.push(`/child/${childId}/pay` as never)}
                      />
                    ) : null}
                  </Card>
                ) : null}

                {fees.invoices.length > 0 ? (
                  <Card>
                    <SectionCap>{t('fees.invoices')}</SectionCap>
                    {fees.invoices.map((invoice) => (
                      <ListRow
                        key={invoice.id}
                        title={invoice.number ?? `#${invoice.id}`}
                        subtitle={formatDate(invoice.issued_on, language)}
                        trailing={formatMoney(invoice.total, fees.currency, language)}
                        onPress={() =>
                          router.push(`/child/${childId}/invoice/${invoice.id}` as never)
                        }
                      />
                    ))}
                  </Card>
                ) : null}

                {fees.structure.length > 0 ? (
                  <Card>
                    <SectionCap>{t('fees.structure')}</SectionCap>
                    {fees.structure.map((line) => (
                      <ListRow
                        key={line.fee_item}
                        title={language === 'fr' && line.fee_item_fr ? line.fee_item_fr : line.fee_item}
                        trailing={formatMoney(line.amount, fees.currency, language)}
                      />
                    ))}
                  </Card>
                ) : null}
              </>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}

function LegendRow({ tone, label, value }: { tone: string; label: string; value: string }) {
  return (
    <View style={styles.legendRow}>
      <View style={[styles.legendDot, { backgroundColor: tone }]} />
      <Text style={styles.legendLabel}>{label}</Text>
      <Text style={styles.legendValue}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  summary: { backgroundColor: colors.primary, borderColor: colors.primaryDeep },
  legend: { flex: 1, gap: spacing.md },
  legendRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  legendDot: { width: 10, height: 10, borderRadius: 5 },
  legendLabel: { flex: 1, fontSize: type.small, color: colors.inkMuted },
  legendValue: { fontSize: type.small, fontWeight: weight.semibold, color: colors.ink },
});
