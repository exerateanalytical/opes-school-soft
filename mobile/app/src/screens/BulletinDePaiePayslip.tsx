import React from 'react';
import { router } from 'expo-router';

import { AppHeader, Card, EmptyState, Muted, Screen, Spacer } from '@/components';
import { useI18n } from '@/i18n';

/**
 * `mobile/bulletin-de-paie-payslip.png`.
 *
 * This screen is in the reference set but does NOT belong to the parent app,
 * and it is worth saying plainly rather than building something that looks
 * plausible: a bulletin de paie is a STAFF payslip. It is produced by the
 * Payroll module for an employee, gated on payroll permissions, and there is
 * no matrix row that would ever grant a guardian access to one - the 32-row
 * table is about a parent's relationship to a CHILD.
 *
 * The PNG is almost certainly a stray from the staff-facing design set. It is
 * rendered here as an explicit "not part of this app" so the screen is
 * accounted for rather than silently dropped, and so nobody wires it to a
 * payroll endpoint later on the assumption it was merely unfinished.
 */
export default function BulletinDePaiePayslip(): React.JSX.Element {
  const { t } = useI18n();

  return (
    <Screen header={<AppHeader title="Bulletin de Paie" onBack={router.back} />}>
      <Card>
        <EmptyState
          tone="denied"
          title={t('common.noAccess')}
          body="A payslip belongs to the staff payroll surface, not the parent app."
        />
        <Spacer size={8} />
        <Muted>
          No row of the guardian scope matrix grants a parent access to an employee record.
        </Muted>
      </Card>
    </Screen>
  );
}
