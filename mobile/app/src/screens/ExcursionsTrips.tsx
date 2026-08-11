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
 * `mobile/excursions-trips.png`.
 *
 * Same standing as SchoolActivities: excursions have no guardian endpoint, so
 * this shows the announcements that mention them rather than a trip list the
 * client would have to invent.
 *
 * Notably absent: a "give permission" control. A permission slip is a consent
 * record with legal weight, and there is no write endpoint for one - the P0
 * write set is fixed at eight operations and this is not among them. A button
 * that appeared to grant consent and posted nowhere would be the single most
 * dangerous thing this app could ship.
 */
export default function ExcursionsTrips(): React.JSX.Element {
  const { t, language } = useI18n();
  const state = useScreenData(() => me.announcements(), []);

  return (
    <Screen header={<AppHeader title={t('activities.excursions')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card>
              <SectionCap>{t('activities.excursions')}</SectionCap>

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
