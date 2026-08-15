/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ChatMessage, ThreadInfo } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { MESSAGE, THREAD } from '../constants.ts'
import { getDisplayNameWithFallback } from './getDisplayName.ts'

/**
 * Story 1.8, AC2 - "who set it" has no persisted field of its own (Story 1.4
 * only wrote the lock *reason* to a column, explicitly to avoid a history
 * walk - it made no such guarantee for the actor). The lifecycle system
 * message that performed a transition is the only place the actor exists,
 * so each Thread State maps to the lifecycle verb(s) that produce it.
 */
const STATE_LIFECYCLE_VERBS: Partial<Record<number, string[]>> = {
	[THREAD.STATE.CLOSED]: [MESSAGE.SYSTEM_TYPE.THREAD_CLOSED],
	[THREAD.STATE.LOCKED]: [MESSAGE.SYSTEM_TYPE.THREAD_LOCKED],
	[THREAD.STATE.ONGOING]: [MESSAGE.SYSTEM_TYPE.THREAD_REOPENED, MESSAGE.SYSTEM_TYPE.THREAD_UNLOCKED],
}

/**
 * Derives the display name of the actor who most recently set a Thread's
 * current state, from the Thread's already-loaded message history rather
 * than a new persisted field (Story 1.8, AC2 - see Dev Notes "Assumption -
 * deriving 'who set it'" for the reasoning).
 *
 * @param messages - the Thread's already-loaded messages (e.g. from the
 * Vuex `messagesList` getter)
 * @param threadId - the Thread id to match
 * @param state - the Thread's current state (THREAD.STATE.*)
 * @return the actor's first-name-only display name, or undefined when no
 * matching lifecycle message is loaded
 */
export function getThreadStateActor(messages: ChatMessage[], threadId: number, state: number): string | undefined {
	const verbs = STATE_LIFECYCLE_VERBS[state]
	if (!verbs) {
		return undefined
	}

	const match = messages.findLast((message) => message.threadId === threadId && verbs.includes(message.systemMessage))
	if (!match) {
		return undefined
	}

	return getDisplayNameWithFallback(match.actorDisplayName, match.actorType, true)
}

/**
 * Builds the header's state summary string (Story 1.8, AC2). Ongoing is
 * always quiet (no summary), matching AC1's "common case stays quiet"
 * principle applied to the header as well as list rows. Closed/Locked never
 * render a dangling "by " or "()" when the actor or reason is unknown - the
 * "no empty affordance" requirement generalised to both.
 *
 * @param state - the Thread's current state (THREAD.STATE.*)
 * @param actorName - the actor's display name, if known (see
 * `getThreadStateActor`)
 * @param lockReason - the Thread's current lock reason, or null when none
 * was given or the Thread has never been locked
 * @return the summary string to render, or undefined when nothing should
 * be shown
 */
export function getThreadStateSummary(state: number, actorName: string | undefined, lockReason: string | null): string | undefined {
	if (state === THREAD.STATE.CLOSED) {
		return actorName
			? t('spreed', 'Closed by {actor}', { actor: actorName })
			: t('spreed', 'Closed')
	}

	if (state === THREAD.STATE.LOCKED) {
		if (lockReason) {
			return actorName
				? t('spreed', 'Locked by {actor} ({reason})', { actor: actorName, reason: lockReason })
				: t('spreed', 'Locked ({reason})', { reason: lockReason })
		}

		return actorName
			? t('spreed', 'Locked by {actor}', { actor: actorName })
			: t('spreed', 'Locked')
	}

	return undefined
}

/**
 * Whether a Thread's current state is Locked (Story 1.9, AC3) - the single
 * seam `NewMessage.vue`'s composer reads to disable itself for a Locked
 * Thread, so a participant who follows a notification into one is not left
 * on a composer that accepts text Story 1.6's server-side guard will refuse.
 *
 * @param threadInfo - the Thread's already-loaded info (e.g. from
 * `chatExtrasStore.getThread()`), or undefined when not yet loaded or when
 * not currently inside a Thread
 * @return whether the Thread is currently Locked
 */
export function isThreadLocked(threadInfo: ThreadInfo | undefined): boolean {
	return threadInfo?.thread.state === THREAD.STATE.LOCKED
}
