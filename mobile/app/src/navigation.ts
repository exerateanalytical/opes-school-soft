import { router } from 'expo-router';

import type { NavItem, NavKey } from '@/components';

/**
 * The five-plus-More bottom navigation, and the one place a screen name is
 * turned into a route.
 *
 * `opes://` deep links arrive from push payloads and from search results
 * (`SearchController` emits them), so the mapping from a link to a route lives
 * here rather than being re-derived at each call site.
 */

export function navItems(t: (key: string) => string, unreadMessages = 0): NavItem[] {
  return [
    { key: 'dashboard', label: t('nav.dashboard'), glyph: '⌂' },
    { key: 'children', label: t('nav.children'), glyph: '👪' },
    { key: 'academics', label: t('nav.academics'), glyph: '📖' },
    { key: 'payments', label: t('nav.payments'), glyph: '💳' },
    { key: 'messages', label: t('nav.messages'), glyph: '💬', badge: unreadMessages },
  ];
}

const routes: Record<NavKey, string> = {
  dashboard: '/(tabs)/dashboard',
  children: '/(tabs)/children',
  academics: '/(tabs)/academics',
  payments: '/(tabs)/payments',
  messages: '/(tabs)/messages',
  more: '/(tabs)/more',
};

export function goTo(key: NavKey): void {
  router.push(routes[key] as never);
}

/**
 * `opes://children/1201/results` → the route that shows it.
 *
 * Unknown links fall back to the dashboard rather than throwing: a push
 * payload is written by the server and may name a screen an older app build
 * does not have, and crashing on it would be the worst possible response.
 */
export function followDeepLink(link: string): void {
  const path = link.replace(/^opes:\/\//, '');
  const [section, id, sub] = path.split('/');

  if (section === 'children' && id) {
    if (sub) {
      router.push(`/child/${id}/${sub}` as never);

      return;
    }

    router.push(`/child/${id}` as never);

    return;
  }

  if (section === 'announcements') {
    router.push('/announcements' as never);

    return;
  }

  goTo('dashboard');
}
