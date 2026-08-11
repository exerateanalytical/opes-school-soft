import React, { useState } from 'react';
import { router } from 'expo-router';

import { AppHeader, Button, Card, Field, Muted, Screen, Spacer, Title } from '@/components';
import { useI18n } from '@/i18n';

/**
 * `mobile/forgot-password-reset.png`.
 *
 * The confirmation is deliberately unconditional - "if that account exists we
 * have sent a link" - for the same reason the sign-in screen has one error
 * message: a reset form that says "no such account" is an account-enumeration
 * oracle wearing a helpful tone.
 */
export default function ForgotPasswordReset(): React.JSX.Element {
  const { t } = useI18n();
  const [identifier, setIdentifier] = useState('');
  const [sent, setSent] = useState(false);

  return (
    <Screen header={<AppHeader title={t('auth.resetTitle')} subtitle={t('auth.resetSubtitle')} onBack={router.back} />}>
      <Card>
        {sent ? (
          <>
            <Title>{t('auth.resetSubtitle')}</Title>
            <Spacer size={8} />
            <Muted>{t('auth.resetSubtitle')}</Muted>
          </>
        ) : (
          <>
            <Field
              label={t('auth.identifier')}
              value={identifier}
              onChangeText={setIdentifier}
              keyboardType="email-address"
            />
            <Spacer />
            <Button
              label={t('common.submit')}
              onPress={() => setSent(true)}
              disabled={identifier.trim() === ''}
            />
          </>
        )}
      </Card>
    </Screen>
  );
}
