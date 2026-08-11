import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  ChildContextCard,
  DetailRow,
  EmptyState,
  Screen,
  ScreenState,
  SectionCap,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';

/**
 * `mobile/child-profile.png` — row 2.
 *
 * `detail` is present on the payload only when the link has custody. Its
 * absence is the school's decision, so the screen shows the "not shared" state
 * rather than a page of dashes that would look like a broken record.
 */
export default function ChildProfile(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const { children } = useSession();
  const child = children.find((candidate) => candidate.id === childId) ?? null;
  const state = useScreenData(() => me.child(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('settings.profile')} onBack={router.back} />}>
      {child ? (
        <ChildContextCard
          name={child.display_name}
          className={child.class}
          matricule={child.matricule}
          switchLabel={t('common.switchChild')}
        />
      ) : null}

      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          state.data.data.detail ? (
            <Card>
              <SectionCap>{t('settings.profile')}</SectionCap>
              {Object.entries(state.data.data.detail).map(([key, value]) => (
                <DetailRow key={key} label={key.replaceAll('_', ' ')} value={String(value ?? '—')} />
              ))}
            </Card>
          ) : (
            <Card>
              <EmptyState tone="denied" title={t('common.noAccess')} />
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
