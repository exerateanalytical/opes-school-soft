import React, { useCallback, useEffect, useState } from 'react';

import { ApiError } from '@/api/client';
import { useI18n } from '@/i18n';
import { EmptyState, Loading, StaleBanner } from './primitives';

/**
 * The one loading/denied/offline dance, written once.
 *
 * Every screen in this app has the same four outcomes, and getting them
 * consistent matters more than usual here because two of them are meaningful
 * to a parent rather than technical:
 *
 *   403 - "your school has not shared this with you". A real, correct answer
 *         about how the school has configured this guardian's link. It is NOT
 *         an error, and rendering it as one ("Something went wrong") would
 *         teach parents to phone the office about a working system.
 *   404 - "we could not find that". On this API a 404 about a child means the
 *         link is gone or was never there, and the app must not editorialise.
 *
 * The other two are ordinary: offline (serve the cache, say so) and failure.
 */

type State<T> =
  | { phase: 'loading' }
  | { phase: 'ready'; data: T; stale: boolean; fetchedAt: number }
  | { phase: 'error'; error: ApiError };

export function useScreenData<T>(
  load: () => Promise<{ data: T; stale: boolean; fetchedAt: number }>,
  deps: readonly unknown[] = [],
): State<T> & { reload: () => void } {
  const [state, setState] = useState<State<T>>({ phase: 'loading' });

  const run = useCallback(async () => {
    setState({ phase: 'loading' });

    try {
      const result = await load();
      setState({ phase: 'ready', ...result });
    } catch (error) {
      setState({
        phase: 'error',
        error:
          error instanceof ApiError ? error : new ApiError(0, 'unknown', 'Something went wrong.'),
      });
    }
    // `load` is recreated per render by design; the caller's deps are the
    // real dependency list.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  useEffect(() => {
    void run();
  }, [run]);

  return { ...state, reload: () => void run() };
}

/** Renders the non-ready phases so a screen only writes its happy path. */
export function ScreenState({
  state,
  children,
}: {
  state: State<unknown> & { reload: () => void };
  children: React.ReactNode;
}): React.JSX.Element {
  const { t } = useI18n();

  if (state.phase === 'loading') return <Loading label={t('common.loading')} />;

  if (state.phase === 'error') {
    if (state.error.isDenied) {
      return <EmptyState tone="denied" title={t('common.noAccess')} />;
    }

    if (state.error.status === 404) {
      return <EmptyState tone="denied" title={t('common.notFound')} />;
    }

    return (
      <EmptyState
        title={state.error.code === 'offline' ? t('common.offline') : state.error.message}
        action={t('common.retry')}
        onAction={state.reload}
      />
    );
  }

  return (
    <>
      {state.stale ? (
        <StaleBanner
          label={t('common.showingCached', {
            time: new Date(state.fetchedAt).toLocaleTimeString(),
          })}
        />
      ) : null}
      {children}
    </>
  );
}
