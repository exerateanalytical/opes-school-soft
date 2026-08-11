import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { getLocales } from 'expo-localization';

import en from './en.json';
import fr from './fr.json';

/**
 * Bilingual by requirement, not as a nicety: this is a Cameroonian bilingual
 * school and half the parents read French.
 *
 * The guardian's language is a SERVER fact (`guardians.language`), so it is
 * seeded from `/v1/me` and the device locale is only the fallback for the
 * sign-in screen, which runs before there is a guardian to ask about. Changing
 * it in Settings writes back through `PATCH /v1/me/profile` (row 29) so the
 * SMS the school sends tomorrow is in the same language as the app today.
 */

export type Language = 'en' | 'fr';

const catalogues = { en, fr } as const;

function deviceLanguage(): Language {
  const tag = getLocales()[0]?.languageCode ?? 'en';

  return tag === 'fr' ? 'fr' : 'en';
}

function lookup(catalogue: unknown, path: string): string | undefined {
  const value = path
    .split('.')
    .reduce<unknown>(
      (node, key) =>
        typeof node === 'object' && node !== null ? (node as Record<string, unknown>)[key] : undefined,
      catalogue,
    );

  return typeof value === 'string' ? value : undefined;
}

type I18nValue = {
  language: Language;
  setLanguage: (language: Language) => void;
  t: (key: string, vars?: Record<string, string | number>) => string;
};

const I18nContext = createContext<I18nValue | null>(null);

export function I18nProvider({
  children,
  initial,
}: {
  children: React.ReactNode;
  initial?: Language;
}): React.JSX.Element {
  const [language, setLanguage] = useState<Language>(initial ?? deviceLanguage());

  const t = useCallback(
    (key: string, vars?: Record<string, string | number>): string => {
      // Falls back to English rather than to the key: a French parent seeing
      // an English word has a worse day than a missing translation, but a
      // parent seeing `fees.balanceDue` has no idea what they are looking at.
      const template = lookup(catalogues[language], key) ?? lookup(catalogues.en, key) ?? key;

      if (!vars) return template;

      return Object.entries(vars).reduce(
        (out, [name, value]) => out.replaceAll(`{{${name}}}`, String(value)),
        template,
      );
    },
    [language],
  );

  const value = useMemo<I18nValue>(() => ({ language, setLanguage, t }), [language, t]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  const value = useContext(I18nContext);

  if (!value) throw new Error('useI18n must be used inside <I18nProvider>.');

  return value;
}

/** Money is minor units on the wire; it becomes a string only here. */
export function formatMoney(amount: number, currency: string, language: Language): string {
  // XAF has no minor unit in practice - francs are whole - so the server's
  // "minor units" are already whole francs. Dividing would invent centimes.
  const zeroDecimal = ['XAF', 'XOF', 'JPY', 'KRW'].includes(currency);
  const value = zeroDecimal ? amount : amount / 100;

  const formatted = new Intl.NumberFormat(language === 'fr' ? 'fr-CM' : 'en-CM', {
    minimumFractionDigits: zeroDecimal ? 0 : 2,
    maximumFractionDigits: zeroDecimal ? 0 : 2,
  }).format(value);

  return `${formatted} ${currency === 'XAF' ? 'FCFA' : currency}`;
}

export function formatDate(iso: string | null | undefined, language: Language): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return iso;

  return new Intl.DateTimeFormat(language === 'fr' ? 'fr-CM' : 'en-CM', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(date);
}
