import React, { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import { Button, HeaderCurve } from '@/components';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/** `mobile/welcome-onboarding.png` — the three-panel first run. */
export default function WelcomeOnboarding(): React.JSX.Element {
  const { t } = useI18n();
  const [step, setStep] = useState(0);

  const panels = [
    { title: t('dashboard.myChildren'), body: t('dashboard.todayIs') },
    { title: t('fees.dashboard'), body: t('fees.subtitle') },
    { title: t('comms.inbox'), body: t('dashboard.checkInbox') },
  ];

  const panel = panels[step] ?? panels[0]!;
  const last = step === panels.length - 1;

  return (
    <View style={styles.root}>
      <View style={styles.hero}>
        <View style={styles.crest}>
          <Text style={styles.crestLetter}>H</Text>
        </View>
      </View>
      <HeaderCurve color={colors.primary} />

      <View style={styles.body}>
        <Text style={styles.title}>{panel.title}</Text>
        <Text style={styles.copy}>{panel.body}</Text>

        <View style={styles.dots}>
          {panels.map((entry, index) => (
            <View key={entry.title} style={[styles.dot, index === step && styles.dotOn]} />
          ))}
        </View>

        <Button
          label={last ? t('auth.login') : t('common.seeMore')}
          onPress={() => (last ? router.replace('/auth/login' as never) : setStep((s) => s + 1))}
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.cream },
  hero: { backgroundColor: colors.primary, alignItems: 'center', paddingVertical: spacing.xxxl * 2 },
  crest: {
    width: 96,
    height: 110,
    borderRadius: 20,
    borderWidth: 3,
    borderColor: colors.gold,
    alignItems: 'center',
    justifyContent: 'center',
  },
  crestLetter: { fontSize: 44, fontWeight: weight.bold, color: colors.gold },
  body: { flex: 1, padding: spacing.xl, gap: spacing.lg, justifyContent: 'center' },
  title: { fontSize: type.h1, fontWeight: weight.bold, color: colors.primary, textAlign: 'center' },
  copy: { fontSize: type.bodyLg, color: colors.inkMuted, textAlign: 'center' },
  dots: { flexDirection: 'row', gap: spacing.sm, justifyContent: 'center', marginVertical: spacing.lg },
  dot: { width: 8, height: 8, borderRadius: radius.pill, backgroundColor: colors.borderStrong },
  dotOn: { backgroundColor: colors.gold, width: 24 },
});
