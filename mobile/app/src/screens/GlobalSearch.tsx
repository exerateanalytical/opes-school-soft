import React, { useEffect, useState } from 'react';
import { router } from 'expo-router';

import { AppHeader, Card, EmptyState, Field, ListRow, Loading, Muted, Screen } from '@/components';
import { me } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { useI18n } from '@/i18n';
import { followDeepLink } from '@/navigation';
import type { SearchHit } from '@/api/types';

/**
 * `mobile/global-search.png`.
 *
 * The server does the scoping - per child, per capability, before it queries
 * anything - so this screen renders results without filtering them. That is
 * deliberate: a client-side filter over a wider result set would mean the
 * wider set had already crossed the wire, and a result count alone leaks that
 * a record exists.
 *
 * The two-character floor is the server's rule too, mirrored here only to save
 * a round trip, never to enforce it.
 */
export default function GlobalSearch(): React.JSX.Element {
  const { t } = useI18n();
  const [query, setQuery] = useState('');
  const [hits, setHits] = useState<SearchHit[] | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const term = query.trim();

    if (term.length < 2) {
      setHits(null);

      return;
    }

    // Debounced: a parent typing "Emmanuel" should cost one request, not eight.
    const timer = setTimeout(async () => {
      setBusy(true);

      try {
        const response = await me.search(term);
        setHits(response.data.results);
      } catch (error) {
        if (error instanceof ApiError) setHits([]);
      } finally {
        setBusy(false);
      }
    }, 300);

    return () => clearTimeout(timer);
  }, [query]);

  return (
    <Screen header={<AppHeader title={t('common.search')} onBack={router.back} />}>
      <Field value={query} onChangeText={setQuery} placeholder={t('search.placeholder')} />

      {query.trim().length > 0 && query.trim().length < 2 ? (
        <Muted>{t('search.minLength')}</Muted>
      ) : null}

      {busy ? <Loading /> : null}

      {hits !== null && !busy ? (
        hits.length === 0 ? (
          <Card>
            <EmptyState title={t('search.noResults')} />
          </Card>
        ) : (
          <Card>
            {hits.map((hit) => (
              <ListRow
                key={`${hit.type}-${hit.id}`}
                title={hit.title}
                subtitle={hit.subtitle ?? undefined}
                trailing={hit.type}
                trailingTone="neutral"
                onPress={() => followDeepLink(hit.deep_link)}
              />
            ))}
          </Card>
        )
      ) : null}
    </Screen>
  );
}
