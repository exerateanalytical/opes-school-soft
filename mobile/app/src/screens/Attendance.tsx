import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  BottomNav,
  Card,
  EmptyState,
  ListRow,
  Muted,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  StatTile,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';
import { goTo, navItems } from '@/navigation';

/**
 * `mobile/attendance.png` — rows 11 and 12.
 *
 * The server tells us which SCOPE this parent holds. A summary-only link is
 * not a degraded version of the detail view; it is the whole, correct answer
 * for that guardian, so it says so rather than showing an empty session list
 * that would read as missing data.
 */
export default function Attendance(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.attendance(childId), [childId]);

  return (
    <Screen
      header={<AppHeader title={t('attendance.title')} onBack={router.back} />}
      nav={<BottomNav items={navItems(t)} active="academics" onSelect={goTo} />}
    >
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card>
              <SectionCap>{t('attendance.title')}</SectionCap>
              {state.data.data.summaries.length === 0 ? (
                <EmptyState title={t('common.notAvailable')} />
              ) : (
                state.data.data.summaries.map((summary, index) => {
                  const row = summary as Record<string, number | string>;

                  return (
                    <Row key={index}>
                      <StatTile label={t('attendance.present')} value={String(row.sessions_present ?? '—')} />
                      <StatTile label={t('attendance.absent')} value={String(row.sessions_absent ?? '—')} />
                      <StatTile label={t('attendance.late')} value={String(row.sessions_late ?? '—')} />
                      <StatTile label={t('attendance.expected')} value={String(row.sessions_expected ?? '—')} />
                    </Row>
                  );
                })
              )}
            </Card>

            {state.data.data.scope === 'summary' ? (
              <Card>
                <Muted>{t('attendance.summaryOnly')}</Muted>
              </Card>
            ) : (
              <Card>
                <SectionCap>{t('attendance.title')}</SectionCap>
                {state.data.data.records.length === 0 ? (
                  <EmptyState title={t('common.notAvailable')} />
                ) : (
                  state.data.data.records.map((record, index) => {
                    const row = record as Record<string, string | boolean>;

                    return (
                      <ListRow
                        key={index}
                        title={String(row.session_date ?? '')}
                        subtitle={String(row.session ?? '')}
                        trailing={String(row.status ?? '')}
                        trailingTone={row.status === 'present' ? 'success' : 'warning'}
                      />
                    );
                  })
                )}
              </Card>
            )}
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
