import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/activity-details.png`.
 *
 * Backed by the announcement the school actually published, since activities
 * have no endpoint of their own (see SchoolActivities). There is deliberately
 * no RSVP or permission-slip action: consent for an excursion is a legal
 * record, and inventing a button that posts nowhere would be worse than having
 * no button at all.
 */
export default function ActivityDetails(): React.JSX.Element {
  const { t, language } = useI18n();
  const activityId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.announcements(), []);

  return (
    <Screen header={<AppHeader title={t('activities.details')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          (() => {
            const entry = state.data.data.find((candidate) => candidate.id === activityId);

            if (!entry) {
              return (
                <Card>
                  <EmptyState title={t('common.notFound')} />
                </Card>
              );
            }

            return (
              <Card>
                <View style={styles.head}>
                  <Text style={styles.title}>{entry.title}</Text>
                  <Text style={styles.date}>{formatDate(entry.published_at, language)}</Text>
                </View>

                <Spacer />
                <SectionCap>{t('activities.details')}</SectionCap>
                <Text style={styles.body}>{entry.body ?? t('common.notAvailable')}</Text>

                <Spacer />
                <Muted>{t('common.notAvailable')}</Muted>
              </Card>
            );
          })()
        ) : null}
      </ScreenState>
    </Screen>
  );
}

const styles = StyleSheet.create({
  head: { borderLeftWidth: 3, borderLeftColor: colors.gold, paddingLeft: spacing.md, gap: 2 },
  title: { fontSize: type.h3, fontWeight: weight.bold, color: colors.ink },
  date: { fontSize: type.small, color: colors.inkMuted },
  body: { fontSize: type.body, color: colors.ink, lineHeight: 22 },
});
