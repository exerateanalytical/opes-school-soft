import React from 'react';
import { Redirect } from 'expo-router';

import SplashScreen from '@/screens/SplashScreen';
import { useSession } from '@/state/session';

/**
 * The gate. Three states, and the splash is shown for the first one rather
 * than a blank frame - the session check reads the OS keystore and can take a
 * moment on a cold Android start.
 */
export default function Index(): React.JSX.Element {
  const { status } = useSession();

  if (status === 'loading') return <SplashScreen />;
  if (status === 'signed-out') return <Redirect href="/auth/login" />;

  return <Redirect href="/(tabs)/dashboard" />;
}
