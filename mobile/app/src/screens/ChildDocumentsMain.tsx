import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  EmptyState,
  ListRow,
  Row,
  Screen,
  ScreenState,
  SectionCap,
  StatTile,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { useI18n } from '@/i18n';

/**
 * `mobile/child-documents-main.png` — the documents landing, with counts.
 *
 * The counts are per shelf and only for a shelf this guardian holds. A count
 * for a shelf they cannot see would itself be a disclosure ("there are four
 * documents you may not look at"), which is exactly the leak the search
 * endpoint is designed to avoid — so the tile is absent, not zero.
 */
export default function ChildDocumentsMain(): React.JSX.Element {
  const { t } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.documents(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('documents.title')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            <Card padded={false}>
              <Row>
                {state.data.data.can_view_school_issued ? (
                  <StatTile
                    label={t('documents.schoolIssued')}
                    value={String(state.data.data.school_issued.length)}
                  />
                ) : null}
                {state.data.data.can_view_guardian_supplied ? (
                  <StatTile
                    label={t('documents.guardianSupplied')}
                    value={String(state.data.data.guardian_supplied.length)}
                  />
                ) : null}
              </Row>
            </Card>

            {!state.data.data.can_view_school_issued &&
            !state.data.data.can_view_guardian_supplied ? (
              <Card>
                <EmptyState tone="denied" title={t('common.noAccess')} />
              </Card>
            ) : (
              <Card>
                <SectionCap>{t('documents.title')}</SectionCap>
                <ListRow
                  title={t('documents.title')}
                  subtitle={t('documents.verifyHint')}
                  onPress={() => router.push(`/child/${childId}/documents` as never)}
                />
                <ListRow
                  title={t('health.medicalDocuments')}
                  onPress={() => router.push(`/child/${childId}/medical-documents` as never)}
                />
              </Card>
            )}
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
