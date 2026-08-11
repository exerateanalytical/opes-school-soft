import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Avatar, Card, Chip, Muted, Screen, Spacer } from '@/components';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/digital-school-id-child-id.png`.
 *
 * Built entirely from row 1 (identity) - name, class, matricule - because that
 * is all a school ID card carries and all every valid link is guaranteed to
 * grant. No fetch: the child is already in session state, so the card opens
 * instantly at a school gate with no signal, which is the only moment it
 * matters.
 *
 * The verification strip shows the matricule rather than a signed token: the
 * platform's document verification is serial-based and resolves at the public
 * verify page, and minting a fresh credential here would be a second identity
 * system nobody asked for.
 */
export default function DigitalSchoolIdChildId(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const { children } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;

  return (
    <Screen header={<AppHeader title={t('common.appName')} onBack={router.back} />}>
      <Card padded={false}>
        <View style={styles.head}>
          <Text style={styles.school}>HERITAGE BILINGUAL COLLEGE</Text>
          <Text style={styles.motto}>EXCELLENCE IN EDUCATION</Text>
        </View>

        <View style={styles.body}>
          <Avatar name={child?.display_name ?? '?'} size={96} tone="gold" />
          <Text style={styles.name}>{child?.display_name ?? '—'}</Text>
          <Text style={styles.meta}>{child?.class ?? ''}</Text>
          <Chip label={child?.status ?? t('common.active')} tone="success" dot />

          <Spacer size={8} />
          <View style={styles.codeStrip}>
            <Text style={styles.code}>{child?.matricule ?? '—'}</Text>
          </View>
          <Muted>{t('documents.verifyHint')}</Muted>
        </View>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  head: {
    backgroundColor: colors.primary,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.lg,
    alignItems: 'center',
    gap: 2,
  },
  school: { fontSize: type.body, fontWeight: weight.bold, color: colors.onPrimary, letterSpacing: 2 },
  motto: { fontSize: type.caption, color: colors.gold, letterSpacing: 1 },
  body: { alignItems: 'center', gap: spacing.sm, padding: spacing.xl },
  name: { fontSize: type.h2, fontWeight: weight.bold, color: colors.ink },
  meta: { fontSize: type.body, color: colors.inkMuted },
  codeStrip: {
    borderWidth: 2,
    borderColor: colors.gold,
    backgroundColor: colors.goldSoft,
    borderRadius: radius.md,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xl,
  },
  code: { fontSize: type.title, fontWeight: weight.bold, color: colors.primary, letterSpacing: 3 },
});
