import React, { useState } from 'react';
import { Alert } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Button,
  Card,
  Chip,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me, writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { enqueue } from '@/storage/outbox';
import { formatDate, useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';

/**
 * `mobile/behaviour-discipline.png` — rows 19, 20 and 21.
 *
 * Three separate grants meet on this one screen and the UI has to keep them
 * apart honestly:
 *
 *   the LIST (row 19)      — that a case exists;
 *   the NARRATIVE (row 20) — what it says. Its absence is a deliberate school
 *                            decision, so the screen says so rather than
 *                            leaving a suspicious blank;
 *   the SIGNATURE (row 21) — acknowledging a sanction. Offered only when the
 *                            server said this parent may, and never for an
 *                            already-signed one: WHEN a parent signed is
 *                            evidentiary and the server refuses a rewrite.
 */
export default function BehaviourDiscipline(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const [busy, setBusy] = useState<number | null>(null);
  const state = useScreenData(() => me.discipline(childId), [childId]);

  async function acknowledge(sanctionId: number): Promise<void> {
    setBusy(sanctionId);

    try {
      const queued = await enqueue({
        label: t('discipline.acknowledge'),
        path: `/me/children/${childId}/sanctions/${sanctionId}/ack`,
        method: 'POST',
      });

      await writes.acknowledgeSanction(childId, sanctionId, queued.idempotencyKey);
      state.reload();
    } catch (error) {
      if (error instanceof ApiError && error.code === 'offline') {
        Alert.alert(t('comms.queued'));
      } else if (error instanceof ApiError) {
        Alert.alert(error.message);
      }
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen
      header={<AppHeader title={t('discipline.title')} onBack={router.back} />}
      nav={<BottomNav items={navItems(t)} active="academics" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.cases.length === 0 ? (
            <Card>
              <EmptyState title={t('discipline.noCases')} />
            </Card>
          ) : (
            <>
              {!state.data.data.can_read_narrative ? (
                <Card>
                  <Muted>{t('discipline.narrativeHidden')}</Muted>
                </Card>
              ) : null}

              {state.data.data.cases.map((entry) => (
                <Card key={entry.id}>
                  <SectionCap>
                    {language === 'fr' && entry.category.name_fr
                      ? entry.category.name_fr
                      : entry.category.name}
                  </SectionCap>

                  <Chip
                    label={entry.is_positive ? t('discipline.positive') : entry.status}
                    tone={entry.is_positive ? 'success' : 'warning'}
                  />
                  <Spacer size={8} />
                  <Muted>{formatDate(entry.occurred_on, language)}</Muted>

                  {entry.description ? (
                    <>
                      <Spacer size={8} />
                      <Muted>{entry.description}</Muted>
                    </>
                  ) : null}

                  {entry.sanctions.map((sanction) => (
                    <ListRow
                      key={sanction.id}
                      title={sanction.type}
                      subtitle={[sanction.starts_on, sanction.ends_on].filter(Boolean).join(' → ')}
                      trailing={sanction.acknowledged_at ? t('discipline.acknowledged') : undefined}
                      trailingTone="success"
                    />
                  ))}

                  {state.data.data.can_acknowledge
                    ? entry.sanctions
                        .filter((sanction) => sanction.acknowledged_at === null)
                        .map((sanction) => (
                          <Button
                            key={`ack-${sanction.id}`}
                            label={t('discipline.acknowledge')}
                            variant="secondary"
                            busy={busy === sanction.id}
                            onPress={() => void acknowledge(sanction.id)}
                          />
                        ))
                    : null}
                </Card>
              ))}
            </>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
