/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { THREAD } from '../../constants.ts'
import { getThreadStateActor, getThreadStateSummary, isThreadLocked } from '../threadState.ts'

/**
 * Builds a minimal lifecycle system message for the given thread/verb/actor.
 *
 * @param {object} options - message shape
 * @param {number} options.id - message id (used to determine "latest")
 * @param {number} options.threadId - thread id the message belongs to
 * @param {string} options.systemMessage - lifecycle verb, e.g. 'thread_locked'
 * @param {string} options.actorDisplayName - actor display name
 * @param {string} [options.actorType] - actor type, defaults to 'users'
 */
function makeLifecycleMessage({ id, threadId, systemMessage, actorDisplayName, actorType = 'users' }) {
	return { id, threadId, systemMessage, actorDisplayName, actorType }
}

describe('threadState', () => {
	describe('getThreadStateActor', () => {
		it('returns undefined when no message matches the thread', () => {
			const messages = [makeLifecycleMessage({ id: 1, threadId: 99, systemMessage: 'thread_locked', actorDisplayName: 'Alice' })]
			expect(getThreadStateActor(messages, 42, THREAD.STATE.LOCKED)).toBeUndefined()
		})

		it('returns undefined when no lifecycle message matches the current state', () => {
			const messages = [makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_created', actorDisplayName: 'Alice' })]
			expect(getThreadStateActor(messages, 42, THREAD.STATE.LOCKED)).toBeUndefined()
		})

		it('returns the actor of a single matching lifecycle message', () => {
			const messages = [makeLifecycleMessage({ id: 5, threadId: 42, systemMessage: 'thread_locked', actorDisplayName: 'Alice Smith' })]
			expect(getThreadStateActor(messages, 42, THREAD.STATE.LOCKED)).toBe('Alice')
		})

		it('returns the latest matching actor, not the first, across a lock/unlock/lock-again sequence', () => {
			const messages = [
				makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_locked', actorDisplayName: 'Alice' }),
				makeLifecycleMessage({ id: 2, threadId: 42, systemMessage: 'thread_unlocked', actorDisplayName: 'Bob' }),
				makeLifecycleMessage({ id: 3, threadId: 42, systemMessage: 'thread_locked', actorDisplayName: 'Carol' }),
			]
			expect(getThreadStateActor(messages, 42, THREAD.STATE.LOCKED)).toBe('Carol')
		})

		it('ignores messages belonging to a different thread id', () => {
			const messages = [
				makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_locked', actorDisplayName: 'Alice' }),
				makeLifecycleMessage({ id: 2, threadId: 43, systemMessage: 'thread_locked', actorDisplayName: 'Someone Else' }),
			]
			expect(getThreadStateActor(messages, 43, THREAD.STATE.LOCKED)).toBe('Someone')
		})

		it('matches either thread_reopened or thread_unlocked for the Ongoing state', () => {
			const reopened = [makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_reopened', actorDisplayName: 'Alice' })]
			const unlocked = [makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_unlocked', actorDisplayName: 'Bob' })]
			expect(getThreadStateActor(reopened, 42, THREAD.STATE.ONGOING)).toBe('Alice')
			expect(getThreadStateActor(unlocked, 42, THREAD.STATE.ONGOING)).toBe('Bob')
		})

		it('matches thread_closed for the Closed state', () => {
			const messages = [makeLifecycleMessage({ id: 1, threadId: 42, systemMessage: 'thread_closed', actorDisplayName: 'Alice' })]
			expect(getThreadStateActor(messages, 42, THREAD.STATE.CLOSED)).toBe('Alice')
		})
	})

	describe('getThreadStateSummary', () => {
		it('returns undefined for Ongoing regardless of actor/reason', () => {
			expect(getThreadStateSummary(THREAD.STATE.ONGOING, 'Alice', null)).toBeUndefined()
			expect(getThreadStateSummary(THREAD.STATE.ONGOING, undefined, null)).toBeUndefined()
		})

		it('Closed with a known actor', () => {
			expect(getThreadStateSummary(THREAD.STATE.CLOSED, 'Alice', null)).toBe('Closed by Alice')
		})

		it('Closed with no known actor renders no empty affordance', () => {
			expect(getThreadStateSummary(THREAD.STATE.CLOSED, undefined, null)).toBe('Closed')
		})

		it('Locked with a reason and a known actor', () => {
			expect(getThreadStateSummary(THREAD.STATE.LOCKED, 'Alice', 'Off topic')).toBe('Locked by Alice (Off topic)')
		})

		it('Locked with a reason but no known actor', () => {
			expect(getThreadStateSummary(THREAD.STATE.LOCKED, undefined, 'Off topic')).toBe('Locked (Off topic)')
		})

		it('Locked with a known actor and no reason', () => {
			expect(getThreadStateSummary(THREAD.STATE.LOCKED, 'Alice', null)).toBe('Locked by Alice')
		})

		it('Locked with neither actor nor reason renders no empty affordance', () => {
			expect(getThreadStateSummary(THREAD.STATE.LOCKED, undefined, null)).toBe('Locked')
		})
	})

	describe('isThreadLocked (Story 1.9, AC3)', () => {
		it('returns false when the Thread info is not yet loaded', () => {
			expect(isThreadLocked(undefined)).toBe(false)
		})

		it('returns false for an Ongoing Thread', () => {
			expect(isThreadLocked({ thread: { state: THREAD.STATE.ONGOING } })).toBe(false)
		})

		it('returns false for a Closed Thread', () => {
			expect(isThreadLocked({ thread: { state: THREAD.STATE.CLOSED } })).toBe(false)
		})

		it('returns true for a Locked Thread', () => {
			expect(isThreadLocked({ thread: { state: THREAD.STATE.LOCKED } })).toBe(true)
		})
	})
})
