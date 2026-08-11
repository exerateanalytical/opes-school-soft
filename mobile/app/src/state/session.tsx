import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';

import { auth, me } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { clearCache } from '@/storage/cache';
import { clearOutbox } from '@/storage/outbox';
import { clearToken, deviceId, readToken, writeToken } from '@/storage/secure';
import type { Capability, Child, Guardian } from '@/api/types';

/**
 * Who is signed in, which child is selected, and what the server says they may
 * see.
 *
 * `capabilities` is held here so a tab bar can hide a tile without six round
 * trips - and it is a RENDERING contract only. Nothing in this file decides
 * access; every screen's data still comes from an endpoint that re-checks.
 * If this list is stale the worst outcome is a wasted request answered 403,
 * which is the correct outcome.
 */

type SessionState = {
  status: 'loading' | 'signed-out' | 'signed-in';
  guardian: Guardian | null;
  children: Child[];
  selectedChildId: number | null;
  globalCapabilities: Capability[];
  asOf: string | null;
};

type SessionValue = SessionState & {
  selectedChild: Child | null;
  signIn: (identifier: string, password: string) => Promise<void>;
  signOut: (allDevices?: boolean) => Promise<void>;
  selectChild: (id: number) => void;
  refresh: () => Promise<void>;
  can: (capability: Capability, childId?: number) => boolean;
};

const SessionContext = createContext<SessionValue | null>(null);

const empty: SessionState = {
  status: 'loading',
  guardian: null,
  children: [],
  selectedChildId: null,
  globalCapabilities: [],
  asOf: null,
};

export function SessionProvider({ children }: { children: React.ReactNode }): React.JSX.Element {
  const [state, setState] = useState<SessionState>(empty);

  const load = useCallback(async () => {
    const token = await readToken();

    if (!token) {
      setState({ ...empty, status: 'signed-out' });

      return;
    }

    try {
      const [profile, dashboard] = await Promise.all([me.show(), me.dashboard()]);

      setState((current) => ({
        status: 'signed-in',
        guardian: profile.data.data.guardian,
        children: dashboard.data.data.children,
        globalCapabilities: dashboard.data.data.capabilities_global,
        asOf: dashboard.data.data.as_of,
        // Keep the parent's choice across a refresh; default to the first
        // child on a cold start.
        selectedChildId:
          current.selectedChildId ?? dashboard.data.data.children[0]?.id ?? null,
      }));
    } catch (error) {
      // Only an auth failure signs the user out. A flat tunnel must not: the
      // cache exists precisely so the app still opens underground.
      if (error instanceof ApiError && error.isAuthFailure) {
        await clearToken();
        setState({ ...empty, status: 'signed-out' });

        return;
      }

      setState((current) => ({ ...current, status: 'signed-in' }));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const signIn = useCallback(
    async (identifier: string, password: string) => {
      const id = await deviceId();

      const response = await auth.token({
        identifier,
        password,
        device_name: Platform.select({ ios: 'iPhone', android: 'Android', default: 'Device' }),
        device_id: id,
        platform: Platform.OS,
      });

      await writeToken({
        value: response.data.token,
        expiresAt: response.data.expires_at,
      });

      await load();
    },
    [load],
  );

  const signOut = useCallback(async (allDevices = false) => {
    try {
      await (allDevices ? auth.logoutAll() : auth.logout());
    } catch {
      // A failed logout still signs this device out locally. Leaving a parent
      // logged in because the network blipped is the worse failure - the token
      // expires in 30 days regardless, and `logout-all` is on the Security
      // screen for the case where the phone is genuinely lost.
    }

    // A shared family phone is the normal case. Everything cached about the
    // first parent's children goes.
    await Promise.all([clearToken(), clearCache(), clearOutbox()]);
    setState({ ...empty, status: 'signed-out' });
  }, []);

  const selectChild = useCallback((id: number) => {
    setState((current) => ({ ...current, selectedChildId: id }));
  }, []);

  const value = useMemo<SessionValue>(() => {
    const selectedChild =
      state.children.find((child) => child.id === state.selectedChildId) ?? null;

    return {
      ...state,
      selectedChild,
      signIn,
      signOut,
      selectChild,
      refresh: load,
      can: (capability, childId) => {
        if (childId === undefined) {
          return (
            state.globalCapabilities.includes(capability) ||
            (selectedChild?.capabilities.includes(capability) ?? false)
          );
        }

        const child = state.children.find((candidate) => candidate.id === childId);

        return child?.capabilities.includes(capability) ?? false;
      },
    };
  }, [state, signIn, signOut, selectChild, load]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);

  if (!value) throw new Error('useSession must be used inside <SessionProvider>.');

  return value;
}
