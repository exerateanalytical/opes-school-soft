import React from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';

import {
  AppHeader,
  Card,
  DetailRow,
  ListRow,
  Muted,
  Screen,
  SectionCap,
  Spacer,
} from '@/components';
import { useI18n } from '@/i18n';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/school-information.png`.
 *
 * The school's own public facts. Static rather than fetched, deliberately:
 * there is no guardian endpoint for the school profile (the `SchoolProfile`
 * module is staff-facing), and this content is the same for every parent, is
 * needed offline at a gate, and changes about once a year.
 *
 * When a `GET /v1/me/school` lands, this becomes a `useScreenData` call and
 * nothing else about the screen changes.
 */
export default function SchoolInformation(): React.JSX.Element {
  const { t } = useI18n();

  return (
    <Screen header={<AppHeader title={t('common.appName')} onBack={router.back} />}>
      <Card padded={false}>
        <View style={styles.head}>
          <View style={styles.crest}>
            <Text style={styles.crestLetter}>H</Text>
          </View>
          <Text style={styles.school}>HERITAGE BILINGUAL COLLEGE</Text>
          <Text style={styles.motto}>{t('common.tagline')}</Text>
        </View>

        <View style={styles.body}>
          <DetailRow label="Type" value="Bilingual secondary" />
          <DetailRow label="Region" value="Centre, Cameroon" />
          <DetailRow label={t('common.academicYear')} value="2023/2024" />
        </View>
      </Card>

      <Card>
        <SectionCap>{t('settings.help')}</SectionCap>
        <ListRow
          title="Call the school office"
          onPress={() => void Linking.openURL('tel:+237000000000')}
        />
        <ListRow
          title="Email the school"
          onPress={() => void Linking.openURL('mailto:info@example.test')}
        />
        <Spacer size={8} />
        <Muted>{t('settings.schoolManaged')}</Muted>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  head: {
    backgroundColor: colors.primary,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.xl,
    alignItems: 'center',
    gap: spacing.xs,
  },
  crest: {
    width: 72,
    height: 84,
    borderRadius: radius.md,
    borderWidth: 3,
    borderColor: colors.gold,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.sm,
  },
  crestLetter: { fontSize: 34, fontWeight: weight.bold, color: colors.gold },
  school: { fontSize: type.bodyLg, fontWeight: weight.bold, color: colors.onPrimary, letterSpacing: 2 },
  motto: { fontSize: type.small, color: colors.gold },
  body: { padding: spacing.lg },
});
