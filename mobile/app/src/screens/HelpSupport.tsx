import React, { useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import { AppHeader, Card, ListRow, Muted, Screen, SectionCap, Spacer } from '@/components';
import { useI18n } from '@/i18n';
import { colors, spacing, type, weight } from '@/theme';

/**
 * `mobile/help-support.png`.
 *
 * The FAQ answers are written to match what the app actually does, which
 * matters more here than anywhere else: the commonest support call this app
 * will generate is "why can't I see my child's fees", and the true answer is
 * that the school controls it per guardian. A help page that said "try
 * refreshing" would send that parent round in circles.
 */
export default function HelpSupport(): React.JSX.Element {
  const { t } = useI18n();
  const [open, setOpen] = useState<number | null>(0);

  const faqs = [
    {
      q: "Why can't I see my child's results or fees?",
      a: 'Your school decides what each guardian can see, per child. If a section is missing, the school has not shared it with you — contact the office to change it.',
    },
    {
      q: 'Why can I only see some of my own payments?',
      a: 'Payments are matched to you by the phone number recorded against them. If a payment you made is missing, ask the office to check the number on the receipt.',
    },
    {
      q: 'Why can I not download a report card or receipt?',
      a: 'Signed documents are issued by the school. The app shows you the verification code instead — quote it at the office, or scan it on the verification page.',
    },
    {
      q: 'Can I pay through the app?',
      a: 'Not yet. Payments are made at the school office. The app will show them once they are recorded.',
    },
    {
      q: 'I changed my phone number and stopped receiving messages.',
      a: 'Update it under My Profile. That also updates the number the school uses for SMS.',
    },
  ];

  return (
    <Screen header={<AppHeader title={t('settings.help')} onBack={router.back} />}>
      <Card>
        <SectionCap>{t('settings.help')}</SectionCap>

        {faqs.map((faq, index) => (
          <Pressable
            key={faq.q}
            onPress={() => setOpen((current) => (current === index ? null : index))}
            style={styles.faq}
          >
            <View style={styles.faqHead}>
              <Text style={styles.question}>{faq.q}</Text>
              <Text style={styles.chevron}>{open === index ? '−' : '+'}</Text>
            </View>
            {open === index ? <Text style={styles.answer}>{faq.a}</Text> : null}
          </Pressable>
        ))}
      </Card>

      <Card>
        <SectionCap>{t('comms.contactTeacher')}</SectionCap>
        <ListRow title="Call the school office" onPress={() => void Linking.openURL('tel:+237000000000')} />
        <ListRow title="Email the school" onPress={() => void Linking.openURL('mailto:info@example.test')} />
        <ListRow title={t('comms.inbox')} onPress={() => router.push('/(tabs)/messages' as never)} />
        <Spacer size={8} />
        <Muted>{t('settings.schoolManaged')}</Muted>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  faq: { paddingVertical: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.divider },
  faqHead: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  question: { flex: 1, fontSize: type.body, fontWeight: weight.medium, color: colors.ink },
  chevron: { fontSize: type.h3, color: colors.primary },
  answer: { fontSize: type.small, color: colors.inkMuted, lineHeight: 20, marginTop: spacing.sm },
});
