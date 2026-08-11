import React from 'react';
import { router } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';

/**
 * `mobile/sports-events.png`.
 *
 * Third of the activity trio, same standing and same honest fallback: the
 * announcements the school actually published. See SchoolActivities for why
 * this is not invented client-side.
 */
export default function SportsEvents(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.announcements(), []);

  return (
    <Screen header={<AppHeader title={t('activities.sports')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card>
              <SectionCap>{t('activities.sports')}</SectionCap>

              {state.data.data.length === 0 ? (
                <EmptyState title={t('common.notAvailable')} />
              ) : (
                state.data.data.map((entry) => (
                  <ListRow
                    key={entry.id}
                    title={entry.title}
                    subtitle={formatDate(entry.published_at, language)}
                    onPress={() => router.push(`/activity/${entry.id}` as never)}
                  />
                ))
              )}
            </Card>

            <Card>
              <Muted>{t('common.notAvailable')}</Muted>
            </Card>
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
