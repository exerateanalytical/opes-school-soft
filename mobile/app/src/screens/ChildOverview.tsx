import React from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Card,
  ChildContextCard,
  Screen,
  ScreenState,
  SectionHeading,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';
import { useSession } from '@/state/session';
import type { Capability } from '@/api/types';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/child-overview.png` — the hub for one child.
 *
 * The tile grid is driven ENTIRELY by the `capabilities` array the server
 * returned for this child. A school that shares results but not fees produces
 * a different hub from one that shares both, and neither parent is shown a
 * door that answers 403 when they push it. That is the point of the rendering
 * contract: the app never offers what the server will refuse.
 */
export default function ChildOverview(): React.JSX.Element {
  const { t } = useI18n();
  const params = useLocalSearchParams<{ id?: string }>();
  const childId = Number(params.id ?? 0);
  const { children, can } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;

  const state = useScreenData(() => me.child(childId), [childId]);

  const tiles: { capability: Capability; label: string; route: string }[] = [
    { capability: 'child.profile_detail.view', label: t('settings.profile'), route: `/child/${childId}/profile` },
    { capability: 'results.report_card.view', label: t('academics.resultsOverview'), route: `/child/${childId}/results` },
    { capability: 'results.attendance_summary.view', label: t('attendance.title'), route: `/child/${childId}/attendance` },
    { capability: 'fees.invoices.view', label: t('fees.dashboard'), route: `/child/${childId}/fees` },
    { capability: 'discipline.list.view', label: t('discipline.title'), route: `/child/${childId}/discipline` },
    { capability: 'child.medical_emergency.view', label: t('health.overview'), route: `/child/${childId}/health` },
    { capability: 'documents.school_issued.view', label: t('documents.title'), route: `/child/${childId}/documents` },
    { capability: 'school.timetable.view', label: t('academics.timetable'), route: `/child/${childId}/timetable` },
    { capability: 'child.guardians.list', label: t('health.emergencyContact'), route: `/child/${childId}/contacts` },
  ];

  return (
    <Screen
      header={
        <AppHeader
          title={child?.display_name ?? t('dashboard.myChildren')}
          subtitle={child?.class ?? undefined}
          onBack={router.back}
        />
      }
      nav={<BottomNav items={navItems(t)} active="children" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {child ? (
          <ChildContextCard
            name={child.display_name}
            className={child.class}
            matricule={child.matricule}
            switchLabel={t('common.switchChild')}
            onSwitch={() => router.push('/(tabs)/children' as never)}
          />
        ) : null}

        <SectionHeading>{t('dashboard.overview')}</SectionHeading>

        <View style={styles.grid}>
          {tiles
            .filter((tile) => can(tile.capability, childId))
            .map((tile) => (
              <Pressable
                key={tile.capability}
                style={styles.tile}
                onPress={() => router.push(tile.route as never)}
              >
                <Text style={styles.tileLabel} numberOfLines={2}>
                  {tile.label}
                </Text>
              </Pressable>
            ))}
        </View>
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.md },
  tile: {
    flexGrow: 1,
    flexBasis: '28%',
    height: 96,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.md,
  },
  tileLabel: {
    fontSize: type.small,
    fontWeight: weight.semibold,
    color: colors.primary,
    textAlign: 'center',
  },
});
