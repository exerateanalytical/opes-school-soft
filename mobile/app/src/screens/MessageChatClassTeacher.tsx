import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';

import { AppHeader, Field, Screen, ScreenState, useScreenData } from '@/components';
import { me, writes } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { enqueue } from '@/storage/outbox';
import { useI18n } from '@/i18n';
import { useSession } from '@/state/session';
import { colors, radius, spacing, type, weight } from '@/theme';

/**
 * `mobile/message-chat-class-teacher.png`.
 *
 * The send path is offline-first and that is not a nicety: parents use this
 * outside a school gate on a phone with one bar. Every send is stamped with an
 * idempotency key WHEN QUEUED, so a reply that reached the server but whose
 * response was lost does not become two messages in a teacher's inbox when the
 * app retries.
 */
export default function MessageChatClassTeacher(): React.JSX.Element {
  const { t } = useI18n();
  const { guardian } = useSession();
  const threadId = Number(useLocalSearchParams<{ id?: string }>().id ?? 0);

  const [draft, setDraft] = useState('');
  const [pending, setPending] = useState<string[]>([]);
  const state = useScreenData(
    async () => ({ data: (await me.messages(threadId)).data, stale: false, fetchedAt: Date.now() }),
    [threadId],
  );

  async function send(): Promise<void> {
    const body = draft.trim();

    if (body === '') return;

    setDraft('');
    setPending((current) => [...current, body]);

    try {
      const queued = await enqueue({
        label: t('comms.send'),
        path: `/me/threads/${threadId}/messages`,
        method: 'POST',
        body: { body },
      });

      await writes.sendMessage(threadId, body, queued.idempotencyKey);
      setPending((current) => current.filter((entry) => entry !== body));
      state.reload();
    } catch (error) {
      // Stays in `pending` and in the outbox: the parent sees it as queued
      // rather than losing what they typed.
      if (!(error instanceof ApiError) || error.code !== 'offline') {
        setPending((current) => current.filter((entry) => entry !== body));
      }
    }
  }

  return (
    <Screen
      header={<AppHeader title={t('comms.classTeacher')} onBack={router.back} compact />}
      scroll={false}
    >
      <KeyboardAvoidingView
        style={styles.fill}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView contentContainerStyle={styles.thread}>
          <ScreenState state={state}>
            {state.phase === 'ready'
              ? state.data.data.map((message) => {
                  const mine = message.sender_id === guardian?.id;

                  return (
                    <View
                      key={message.id}
                      style={[styles.bubble, mine ? styles.bubbleMine : styles.bubbleTheirs]}
                    >
                      {!mine && message.sender_name ? (
                        <Text style={styles.sender}>{message.sender_name}</Text>
                      ) : null}
                      <Text style={[styles.bubbleText, mine && styles.bubbleTextMine]}>
                        {message.body}
                      </Text>
                    </View>
                  );
                })
              : null}
          </ScreenState>

          {pending.map((body) => (
            <View key={body} style={[styles.bubble, styles.bubbleMine, styles.bubblePending]}>
              <Text style={[styles.bubbleText, styles.bubbleTextMine]}>{body}</Text>
              <Text style={styles.queued}>{t('comms.queued')}</Text>
            </View>
          ))}
        </ScrollView>

        <View style={styles.composer}>
          <View style={styles.fill}>
            <Field value={draft} onChangeText={setDraft} placeholder={t('comms.typeMessage')} />
          </View>
          <Pressable onPress={send} style={styles.sendButton}>
            <Text style={styles.sendLabel}>{t('comms.send')}</Text>
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  thread: { padding: spacing.lg, gap: spacing.md },
  bubble: { maxWidth: '82%', padding: spacing.md, borderRadius: radius.lg, gap: 2 },
  bubbleMine: { alignSelf: 'flex-end', backgroundColor: colors.primary },
  bubbleTheirs: { alignSelf: 'flex-start', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  bubblePending: { opacity: 0.6 },
  bubbleText: { fontSize: type.body, color: colors.ink },
  bubbleTextMine: { color: colors.onPrimary },
  sender: { fontSize: type.label, fontWeight: weight.semibold, color: colors.primaryInk },
  queued: { fontSize: type.caption, color: colors.onPrimaryMuted },

  composer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    padding: spacing.md,
    backgroundColor: colors.surface,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  sendButton: {
    height: 52,
    paddingHorizontal: spacing.lg,
    borderRadius: radius.md,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendLabel: { color: colors.onPrimary, fontWeight: weight.semibold },
});
