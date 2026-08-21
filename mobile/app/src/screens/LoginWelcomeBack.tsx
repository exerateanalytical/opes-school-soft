import React, { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';

import { Button, Field, HeaderCurve } from '@/components';
import { ApiError } from '@/api/client';
import { useSession } from '@/state/session';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/login-welcome-back.png` — the door.
 *
 * The single most important behaviour here is the ERROR MESSAGE. The server
 * answers every credential failure identically and deliberately - wrong
 * password, unknown email, suspended user, archived guardian, a staff account
 * with no guardian row all return the same 422 - so that the sign-in screen
 * cannot be used to discover whether a parent has an account at this school.
 * This screen must not undo that by guessing a friendlier, more specific
 * message from the status code.
 */
export default function LoginWelcomeBack(): React.JSX.Element {
  const { t } = useI18n();
  const { signIn } = useSession();

  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(): Promise<void> {
    setBusy(true);
    setError(null);

    try {
      await signIn(identifier.trim(), password);
      router.replace('/(tabs)/dashboard' as never);
    } catch (caught) {
      setError(
        caught instanceof ApiError && caught.code === 'offline'
          ? t('common.offline')
          : // One message for every credential failure. See the docblock.
            t('auth.invalidCredentials'),
      );
    } finally {
      setBusy(false);
    }
  }

  // Only meaningful against a locally seeded backend (PortalShowcaseSeeder /
  // DemoDataSeeder), which is what `__DEV__` is a reasonable proxy for here.
  // Never wire this into a production build.
  async function submitDemo(): Promise<void> {
    setIdentifier('demo.guardian1@opeschool.test');
    setPassword('password');
    setBusy(true);
    setError(null);

    try {
      await signIn('demo.guardian1@opeschool.test', 'password');
      router.replace('/(tabs)/dashboard' as never);
    } catch (caught) {
      setError(
        caught instanceof ApiError && caught.code === 'offline'
          ? t('common.offline')
          : t('auth.invalidCredentials'),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={styles.root}>
      <View style={styles.topBand}>
        <SafeAreaView edges={['top']} />
      </View>
      <HeaderCurve color={colors.primary} />

      <ScrollView contentContainerStyle={styles.body} keyboardShouldPersistTaps="handled">
        <View style={styles.crestBlock}>
          <View style={styles.crest}>
            <Text style={styles.crestLetter}>H</Text>
          </View>
          <Text style={styles.wordmark}>HERITAGE</Text>
          <Text style={styles.sub}>BILINGUAL COLLEGE</Text>
          <Text style={styles.tagline}>{t('common.tagline')}</Text>
        </View>

        <Text style={styles.welcome}>{t('auth.welcomeBack')}</Text>
        <Text style={styles.welcomeSub}>{t('auth.loginSubtitle')}</Text>

        <View style={styles.form}>
          <Field
            value={identifier}
            onChangeText={setIdentifier}
            placeholder={t('auth.identifier')}
            keyboardType="email-address"
          />
          <Field
            value={password}
            onChangeText={setPassword}
            placeholder={t('auth.password')}
            secureTextEntry
            error={error ?? undefined}
          />

          <View style={styles.optionsRow}>
            <Pressable style={styles.remember} onPress={() => setRemember((on) => !on)}>
              <View style={[styles.checkbox, remember && styles.checkboxOn]}>
                {remember ? <Text style={styles.checkGlyph}>✓</Text> : null}
              </View>
              <Text style={styles.rememberLabel}>{t('auth.rememberMe')}</Text>
            </Pressable>

            <Pressable onPress={() => router.push('/auth/forgot' as never)}>
              <Text style={styles.link}>{t('auth.forgotPassword')}</Text>
            </Pressable>
          </View>

          <Button
            label={t('auth.login')}
            onPress={submit}
            busy={busy}
            disabled={identifier.trim() === '' || password === ''}
          />

          {__DEV__ ? (
            <Pressable style={styles.demoButton} onPress={submitDemo} disabled={busy}>
              <Text style={styles.demoButtonLabel}>Demo Login</Text>
            </Pressable>
          ) : null}
        </View>

        <View style={styles.registerRow}>
          <Text style={styles.registerText}>{t('auth.noAccount')} </Text>
          <Text style={styles.link}>{t('auth.register')}</Text>
        </View>
      </ScrollView>

      <View style={styles.footer}>
        <HeaderCurve color={colors.primary} />
        <View style={styles.footerInner}>
          <View style={styles.footerCol}>
            <Text style={styles.footerText}>{t('auth.safetyOne')}</Text>
            <Text style={styles.footerText}>{t('auth.safetyTwo')}</Text>
          </View>
          <View style={styles.footerDivider} />
          <View style={styles.footerCol}>
            <Text style={styles.footerText}>{t('auth.secureOne')}</Text>
            <Text style={styles.footerText}>{t('auth.secureTwo')}</Text>
          </View>
        </View>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.cream },
  topBand: { backgroundColor: colors.primary },
  body: { paddingHorizontal: spacing.xl, paddingBottom: spacing.xl, gap: spacing.md },

  crestBlock: { alignItems: 'center', gap: spacing.xs, marginTop: spacing.lg },
  crest: {
    width: 96,
    height: 110,
    borderRadius: 20,
    borderWidth: 3,
    borderColor: colors.gold,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  crestLetter: { fontSize: 44, fontWeight: weight.bold, color: colors.gold },
  wordmark: {
    fontSize: type.h2,
    fontWeight: weight.bold,
    color: colors.primary,
    letterSpacing: 5,
  },
  sub: { fontSize: type.label, color: colors.gold, letterSpacing: 2 },
  tagline: { fontSize: type.bodyLg, color: colors.ink, marginTop: spacing.xs },

  welcome: {
    fontSize: type.h2,
    fontWeight: weight.bold,
    color: colors.primary,
    textAlign: 'center',
    marginTop: spacing.lg,
  },
  welcomeSub: { fontSize: type.bodyLg, color: colors.inkMuted, textAlign: 'center' },

  form: { gap: spacing.md, marginTop: spacing.lg },
  optionsRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  remember: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: radius.sm,
    borderWidth: 2,
    borderColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxOn: { backgroundColor: colors.white },
  checkGlyph: { color: colors.primary, fontSize: type.body, fontWeight: weight.bold },
  rememberLabel: { fontSize: type.body, color: colors.ink },
  link: { fontSize: type.body, fontWeight: weight.semibold, color: colors.primary },
  demoButton: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.sm,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.primary,
    borderStyle: 'dashed',
  },
  demoButtonLabel: { fontSize: type.body, fontWeight: weight.semibold, color: colors.primary },

  registerRow: { flexDirection: 'row', justifyContent: 'center', marginTop: spacing.lg },
  registerText: { fontSize: type.body, color: colors.inkMuted },

  footer: { backgroundColor: colors.primary },
  footerInner: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingBottom: spacing.xl,
    paddingHorizontal: spacing.lg,
    gap: spacing.lg,
  },
  footerCol: { gap: 2 },
  footerDivider: { width: 1, height: 32, backgroundColor: colors.onPrimaryMuted, opacity: 0.4 },
  footerText: { fontSize: type.small, color: colors.onPrimary },
});
