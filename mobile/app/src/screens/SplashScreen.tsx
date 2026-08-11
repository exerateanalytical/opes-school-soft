import React from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { HeaderCurve } from '@/components';
import { colors, spacing, type, weight } from '@/theme';
import { useI18n } from '@/i18n';

/** `mobile/splash-screen.png` — the crest on deep green while the session loads. */
export default function SplashScreen(): React.JSX.Element {
  const { t } = useI18n();

  return (
    <View style={styles.root}>
      <View style={styles.centre}>
        <View style={styles.crest}>
          <Text style={styles.crestLetter}>H</Text>
        </View>
        <Text style={styles.wordmark}>HERITAGE</Text>
        <Text style={styles.sub}>BILINGUAL COLLEGE</Text>
        <Text style={styles.tagline}>{t('common.tagline')}</Text>
      </View>

      <HeaderCurve color={colors.primaryDeep} />
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.primary, justifyContent: 'space-between' },
  centre: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.sm },
  crest: {
    width: 116,
    height: 132,
    borderRadius: 24,
    borderWidth: 3,
    borderColor: colors.gold,
    backgroundColor: colors.primaryDeep,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.lg,
  },
  crestLetter: { fontSize: 56, fontWeight: weight.bold, color: colors.gold },
  wordmark: {
    fontSize: type.display,
    fontWeight: weight.bold,
    color: colors.white,
    letterSpacing: 6,
  },
  sub: { fontSize: type.small, color: colors.gold, letterSpacing: 3 },
  tagline: { fontSize: type.body, color: colors.onPrimaryMuted, marginTop: spacing.sm },
});
