import React, { useState } from 'react';
import { Alert } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Button,
  Card,
  Field,
  ListRow,
  Muted,
  Screen,
  SectionCap,
  Spacer,
} from '@/components';
import { writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { enqueue } from '@/storage/outbox';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';

/**
 * `mobile/teacher-school-contact.png` — and the meeting request (row 27).
 *
 * The meeting form is the interesting half. The time a parent picks is a
 * PREFERENCE, not a booking - the server records it with
 * `requested_by = guardian` so the office can tell an ask from a commitment -
 * and the copy says so, because a parent who believed they had booked a slot
 * and turned up to an empty office would rightly be furious.
 *
 * Row 27 needs custody, so the form is offered only when the server said so.
 */
export default function TeacherSchoolContact(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const { can } = useSession();

  const [agenda, setAgenda] = useState('');
  const [busy, setBusy] = useState(false);

  async function request(): Promise<void> {
    setBusy(true);

    try {
      const preferredAt = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString();

      const queued = await enqueue({
        label: t('comms.requestMeeting'),
        path: `/me/children/${childId}/meetings`,
        method: 'POST',
        body: { preferred_at: preferredAt, agenda },
      });

      await writes.requestMeeting(
        childId,
        { preferred_at: preferredAt, agenda: agenda || undefined },
        queued.idempotencyKey,
      );

      setAgenda('');
      Alert.alert(t('settings.changesSaved'));
    } catch (error) {
      Alert.alert(
        error instanceof ApiError && error.code === 'offline'
          ? t('comms.queued')
          : error instanceof ApiError
            ? error.message
            : t('common.retry'),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen header={<AppHeader title={t('comms.contactTeacher')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('comms.inbox')}</SectionCap>
        <ListRow
          title={t('comms.classTeacher')}
          subtitle={t('comms.newMessage')}
          onPress={() => router.push('/(tabs)/messages' as never)}
        />
      </Card>

      {can('meeting.request', childId) ? (
        <Card>
          <SectionCap>{t('comms.requestMeeting')}</SectionCap>
          <Field
            label={t('activities.details')}
            value={agenda}
            onChangeText={setAgenda}
            multiline
            autoCapitalize="sentences"
          />
          <Spacer size={8} />
          {/* The time is a preference, not a booking. Say it before they tap. */}
          <Muted>{t('comms.requestMeeting')} — {t('common.today')} + 7</Muted>
          <Spacer />
          <Button label={t('common.submit')} onPress={request} busy={busy} />
        </Card>
      ) : null}
    </Screen>
  );
}
