import React from 'react';
import { router, useLocalSearchParams } from 'expo-router';

import {
  AppHeader,
  Card,
  Chip,
  EmptyState,
  ListRow,
  Muted,
  Screen,
  ScreenState,
  SectionCap,
  Spacer,
  useScreenData,
} from '@/components';
import { me } from '@/api/endpoints';
import { formatDate, useI18n } from '@/i18n';

/**
 * `mobile/child-documents.png` — rows 22 and 23, two shelves.
 *
 * School-issued documents deliberately carry NO download button. The server
 * returns a verification descriptor for them, because the only path to a
 * signed PDF is gated on a staff permission a parent must never hold. So the
 * app shows the code the parent can quote at the office - which is the
 * artefact the school actually hands over - rather than a button that would
 * fail.
 *
 * Guardian-supplied documents do download: the guardian uploaded them.
 */
export default function ChildDocuments(): React.JSX.Element {
  const { t, language } = useI18n();
  const childId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);
  const state = useScreenData(() => me.documents(childId), [childId]);

  return (
    <Screen header={<AppHeader title={t('documents.title')} onBack={router.back} />}>
      <ScreenState state={state}>
        {state.phase === 'ready' ? (
          <>
            {state.data.data.can_view_school_issued ? (
              <Card>
                <SectionCap>{t('documents.schoolIssued')}</SectionCap>

                {state.data.data.school_issued.length === 0 ? (
                  <EmptyState title={t('common.notAvailable')} />
                ) : (
                  <>
                    {state.data.data.school_issued.map((document) => (
                      <ListRow
                        key={document.id}
                        title={document.verification_code ?? `#${document.id}`}
                        subtitle={formatDate(document.issued_at, language)}
                        trailing={t('common.verify')}
                        trailingTone="gold"
                        onPress={() =>
                          router.push(`/child/${childId}/document/school/${document.id}` as never)
                        }
                      />
                    ))}
                    <Spacer size={8} />
                    <Muted>{t('documents.noBytes')}</Muted>
                  </>
                )}
              </Card>
            ) : null}

            {state.data.data.can_view_guardian_supplied ? (
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
            ) : null}
          </>
        ) : null}
      </ScreenState>
    </Screen>
  );
}
