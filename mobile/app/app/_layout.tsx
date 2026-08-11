import React from 'react';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { I18nProvider } from '@/i18n';
import { SessionProvider } from '@/state/session';

/**
 * The root. Providers only - no screen decides anything about the session or
 * the language for itself.
 *
 * `light` status bar because every screen's top band is the deep green header;
 * a dark one disappears into it.
 */
export default function RootLayout(): React.JSX.Element {
  return (
    <SafeAreaProvider>
      <I18nProvider>
        <SessionProvider>
          <StatusBar style="light" />
          <Stack screenOptions={{ headerShown: false, animation: 'slide_from_right' }} />
        </SessionProvider>
      </I18nProvider>
    </SafeAreaProvider>
  );
}
