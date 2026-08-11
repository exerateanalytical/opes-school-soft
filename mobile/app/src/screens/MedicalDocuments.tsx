import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

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
 * `mobile/medical-documents.png`.
 *
 * Medical paperwork lives on the ordinary document shelves (rows 22/23), not
 * behind the medical rows - a vaccination card a parent uploaded is a
 * guardian-supplied document like any other. So this reads the documents
 * endpoint and shows the guardian-supplied shelf, which is the one with actual
 * files behind it.
 */
export default function MedicalDocuments(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.documents(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('health.medicalDocuments')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          !state.data.data.can_view_guardian_supplied ? (
            <Card>
              <EmptyState tone="denied" title={t('common.noAccess')} />
            </Card>
          ) : (
            <Card>
              <SectionCap>{t('documents.guardianSupplied')}</SectionCap>

              {state.data.data.guardian_supplied.length === 0 ? (
                <EmptyState title={t('common.notAvailable')} />
              ) : (
                state.data.data.guardian_supplied.map((document) => (
                  <ListRow
                    key={document.id}
                    title={document.title}
                    subtitle={
                      document.expires_on
                        ? t('documents.expiresOn', {
                            date: formatDate(document.expires_on, language),
                          })
                        : formatDate(document.issued_on, language)
                    }
                    trailing={t(`documents.${document.verification_status}`)}
                    trailingTone={document.verification_status === 'verified' ? 'success' : 'neutral'}
                    onPress={() =>
                      router.push(`/child/${childId}/document/supplied/${document.id}` as never)
                    }
                  />
                ))
              )}
            </Card>
          )
        ) : null}
      </ScreenState>
    </Screen>
  );
}
