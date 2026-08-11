import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Avatar,
  Card,
  DetailRow,
  EmptyState,
  Muted,
  Screen,
  ScreenState,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/health-id.png` and `mobile/opes-health-id.png`.
 *
 * The emergency card: what a paramedic or a school nurse needs in thirty
 * seconds. Built on the ROW 3 scope deliberately - this is the one health
 * screen that should work for a link the school gave emergency access to and
 * nothing more, because that is exactly the case it exists for.
 *
 * `Cache-Control: private, no-store` is honoured: nothing here touches disk,
 * so a lost phone does not carry a child's blood group in AsyncStorage.
 */
export default function HealthId(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const { children } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;

  const state = useScreenData(
    async () => ({ data: await me.medical(childId), stale: false, fetchedAt: Date.now() }),
    [childId],
  );

  return (
    <Screen header={<AppHeader title={t('health.healthId')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const record = state.data.data as Record<string, unknown>;

            return (
              <Card padded={false}>
                <View style={styles.cardHead}>
                  <Avatar name={child?.display_name ?? '?'} size={64} tone="gold" />
                  <View style={styles.headBody}>
                    <Text style={styles.name}>{child?.display_name ?? '—'}</Text>
                    <Text style={styles.meta}>{child?.class ?? ''}</Text>
                    <Text style={styles.meta}>{child?.matricule ?? ''}</Text>
                  </View>
                </View>

                <View style={styles.cardBody}>
                  <DetailRow
                    label={t('health.bloodGroup')}
                    value={String(record.blood_group ?? '—')}
                    tone="danger"
                  />
                  <DetailRow label={t('health.allergies')} value={String(record.allergies ?? '—')} />
                  <DetailRow label={t('health.conditions')} value={String(record.conditions ?? '—')} />
                  <DetailRow
                    label={t('health.emergencyContact')}
                    value={String(record.emergency_contact_phone ?? record.emergency_contact ?? '—')}
                  />

                  <Spacer size={8} />
                  <Muted>{t('health.emergencyOnly')}</Muted>
                </View>
              </Card>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  cardHead: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.primary,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.lg,
  },
  headBody: { flex: 1, gap: 2 },
  name: { fontSize: type.title, fontWeight: weight.bold, color: colors.onPrimary },
  meta: { fontSize: type.small, color: colors.onPrimaryMuted },
  cardBody: { padding: spacing.lg },
});
