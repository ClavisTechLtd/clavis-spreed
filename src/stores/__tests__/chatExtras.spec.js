/*
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BrowserStorage from '../../services/BrowserStorage.js'
import { getTalkConfig } from '../../services/CapabilitiesManager.ts'
import { EventBus } from '../../services/EventBus.ts'
import { getSingleThreadForConversation, setThreadState } from '../../services/messagesService.ts'
import { useChatExtrasStore } from '../chatExtras.ts'

vi.mock('vuex', async () => {
	const vuex = await vi.importActual('vuex')
	return {
		...vuex,
		useStore: vi.fn(),
	}
})

vi.mock('../../services/messagesService.ts', async () => {
	const actual = await vi.importActual('../../services/messagesService.ts')
	return {
		...actual,
		setThreadState: vi.fn(),
		getSingleThreadForConversation: vi.fn(),
	}
})

vi.mock('../../services/CapabilitiesManager.ts', () => ({
	getTalkConfig: vi.fn(),
}))

vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(),
}))

describe('chatExtrasStore', () => {
	const token = 'TOKEN'
	let chatExtrasStore

	beforeEach(async () => {
		setActivePinia(createPinia())
		chatExtrasStore = useChatExtrasStore()
	})

	afterEach(async () => {
		vi.clearAllMocks()
	})

	describe('reply message', () => {
		it('adds reply message id to the store', () => {
			// Act
			chatExtrasStore.setParentIdToReply({ token, id: 101 })

			// Assert
			expect(chatExtrasStore.getParentIdToReply(token)).toBe(101)
		})

		it('clears reply message', () => {
			// Arrange
			chatExtrasStore.setParentIdToReply({ token, id: 101 })

			// Act
			chatExtrasStore.removeParentIdToReply(token)

			// Assert
			expect(chatExtrasStore.getParentIdToReply(token)).not.toBeDefined()
		})
	})

	describe('current input message', () => {
		it('sets current input message', () => {
			// Act
			chatExtrasStore.setChatInput({ token: 'token-1', text: 'message-1' })

			// Assert
			expect(chatExtrasStore.getChatInput('token-1')).toStrictEqual('message-1')
			expect(BrowserStorage.getItem('chatInput_token-1')).toBe('message-1')
		})

		it('clears current input message', () => {
			// Arrange
			chatExtrasStore.setChatInput({ token: 'token-1', text: 'message-1' })

			// Act
			chatExtrasStore.removeChatInput('token-1')

			// Assert
			expect(chatExtrasStore.chatInput['token-1']).not.toBeDefined()
			expect(chatExtrasStore.getChatInput('token-1')).toBe('')
			expect(BrowserStorage.getItem('chatInput_token-1')).toBe(null)
		})

		it('restores chat input from the browser storage if any', () => {
			// Arrange
			BrowserStorage.setItem('chatInput_token-1', 'message draft')

			// Act
			chatExtrasStore.restoreChatInput('token-1')

			// Assert
			expect(chatExtrasStore.getChatInput('token-1')).toStrictEqual('message draft')

			// Arrange 2 - no chat input in the browser storage
			chatExtrasStore.removeChatInput('token-1')
			// Act
			chatExtrasStore.restoreChatInput('token-1')
			// Assert
			expect(chatExtrasStore.getChatInput('token-1')).toBe('')
		})
	})

	describe('current edit input message', () => {
		it('sets current edit input message', () => {
			// Act
			chatExtrasStore.setChatEditInput({ token: 'token-1', text: 'This is an edited message' })
			chatExtrasStore.setMessageIdToEdit('token-1', 'id-1')

			// Assert
			expect(chatExtrasStore.getChatEditInput('token-1')).toStrictEqual('This is an edited message')
			expect(chatExtrasStore.getMessageIdToEdit('id-1')).toBe(undefined)
		})

		it('clears current edit input message', () => {
			// Arrange
			chatExtrasStore.setChatEditInput({ token: 'token-1', text: 'This is an edited message' })
			chatExtrasStore.setMessageIdToEdit('token-1', 'id-1')

			// Act
			chatExtrasStore.removeMessageIdToEdit('token-1')

			// Assert
			expect(chatExtrasStore.chatEditInput['token-1']).not.toBeDefined()
			expect(chatExtrasStore.getChatEditInput('token-1')).toBe('')
		})
	})

	describe('purge store', () => {
		it('clears store for provided token', async () => {
			// Arrange
			chatExtrasStore.setParentIdToReply({ token: 'token-1', id: 101 })
			chatExtrasStore.setChatInput({ token: 'token-1', text: 'message-1' })

			// Act
			chatExtrasStore.purgeChatExtras('token-1')

			// Assert
			expect(chatExtrasStore.parentToReply['token-1']).not.toBeDefined()
			expect(chatExtrasStore.chatInput['token-1']).not.toBeDefined()
		})
	})

	describe('text parsing', () => {
		it('should render mentions properly when editing message', () => {
			// Arrange
			const parameters = {
				'mention-call1': { type: 'call', name: 'Conversation101', 'mention-id': 'all' },
				'mention-user1': { type: 'user', name: 'Alice Joel', id: 'alice', 'mention-id': 'alice' },
			}
			// Act
			chatExtrasStore.setChatEditInput({
				token: 'token-1',
				text: 'Hello {mention-call1} and {mention-user1}',
				parameters,
			})
			// Assert
			expect(chatExtrasStore.getChatEditInput('token-1')).toBe('Hello @"all" and @"alice"')
		})

		it('should store chat input without escaping special symbols', () => {
			// Arrange
			const message = 'These are special symbols &amp; &lt; &gt; &sect;'
			// Act
			chatExtrasStore.setChatInput({ token: 'token-1', text: message })
			// Assert
			expect(chatExtrasStore.getChatInput('token-1')).toBe('These are special symbols & < > §')
		})
		it('should remove leading/trailing whitespaces', () => {
			// Arrange
			const message = '   Many whitespaces   '
			// Act
			chatExtrasStore.setChatInput({ token: 'token-1', text: message })
			// Assert
			expect(chatExtrasStore.getChatInput('token-1')).toBe('Many whitespaces')
		})
	})

	describe('thread management authority', () => {
		// Story 1.3, AC6: canManageThread() is the one computed-getter seam
		// exposing the server's authority answer - components must never
		// re-derive it themselves.
		const baseThreadInfo = {
			thread: { id: 1, roomToken: token, title: 'Thread', lastMessageId: 1, lastActivity: 0, numReplies: 0, state: 0 },
			attendee: { notificationLevel: 0 },
			canManage: false,
			first: null,
			last: null,
		}

		it('returns false for a thread that is not known to the store', () => {
			expect(chatExtrasStore.canManageThread(token, 999)).toBe(false)
		})

		it('returns the stored canManage value once the thread is loaded', () => {
			// Arrange
			chatExtrasStore.addThread(token, { ...baseThreadInfo, canManage: true })

			// Assert
			expect(chatExtrasStore.canManageThread(token, 1)).toBe(true)
		})

		it('returns false when the stored thread cannot manage', () => {
			// Arrange
			chatExtrasStore.addThread(token, { ...baseThreadInfo, canManage: false })

			// Assert
			expect(chatExtrasStore.canManageThread(token, 1)).toBe(false)
		})

		it('preserves canManage on a partial updateThread() call that omits it', async () => {
			// Arrange
			chatExtrasStore.addThread(token, { ...baseThreadInfo, canManage: true })

			// Act - update only the thread title, canManage omitted from the payload
			await chatExtrasStore.updateThread(token, 1, { thread: { ...baseThreadInfo.thread, title: 'Renamed' } })

			// Assert
			expect(chatExtrasStore.canManageThread(token, 1)).toBe(true)
			expect(chatExtrasStore.getThread(token, 1).thread.title).toBe('Renamed')
		})

		it('overwrites canManage when the updateThread() payload includes it', async () => {
			// Arrange
			chatExtrasStore.addThread(token, { ...baseThreadInfo, canManage: true })

			// Act
			await chatExtrasStore.updateThread(token, 1, { canManage: false })

			// Assert
			expect(chatExtrasStore.canManageThread(token, 1)).toBe(false)
		})
	})

	describe('updateThread for a Thread the store has never loaded (Story 1.8, AC9)', () => {
		// AC9: a relayed state-change message for a Thread the store has
		// never seen must not write a half-populated entry built from the
		// partial payload - it either adopts the full Thread the server
		// returns, or leaves the store untouched.
		const threadId = 5
		const fullThreadInfo = {
			thread: { id: threadId, roomToken: token, title: 'Fetched title', lastMessageId: 3, lastActivity: 0, numReplies: 2, state: 0, lockReason: null },
			attendee: { notificationLevel: 0 },
			canManage: false,
			first: null,
			last: null,
		}

		it('adopts the full server-fetched Thread rather than the partial payload', async () => {
			// Arrange
			getSingleThreadForConversation.mockResolvedValueOnce({ data: { ocs: { data: fullThreadInfo } } })

			// Act - a partial payload (only `thread.title`) for a Thread
			// this store instance has never loaded
			await chatExtrasStore.updateThread(token, threadId, { thread: { ...fullThreadInfo.thread, title: 'Stale partial title' } })

			// Assert - the store holds the complete, server-fetched Thread,
			// not the partial one the relay handed it
			expect(getSingleThreadForConversation).toHaveBeenCalledWith(token, threadId)
			expect(chatExtrasStore.getThread(token, threadId)).toStrictEqual(fullThreadInfo)
		})

		it('leaves the store untouched when the fetch itself fails, rather than creating a half-populated entry', async () => {
			// Arrange
			getSingleThreadForConversation.mockRejectedValueOnce(new Error('not found'))
			// test-setup.js makes console.error throw; fetchSingleThread()
			// logs the caught error, so this expected failure must be
			// silenced locally.
			const consoleErrorMock = vi.spyOn(console, 'error').mockImplementation(() => {})

			// Act
			await chatExtrasStore.updateThread(token, threadId, { thread: { ...fullThreadInfo.thread, title: 'Stale partial title' } })

			// Assert - no entry at all, never a partial one that later
			// reads as loaded
			expect(chatExtrasStore.getThread(token, threadId)).toBeUndefined()
			consoleErrorMock.mockRestore()
		})
	})

	describe('thread state transitions', () => {
		// Story 1.4, AC1-AC4, AC10, AC11, AC14
		const baseThreadInfo = {
			thread: { id: 1, roomToken: token, title: 'Thread', lastMessageId: 1, lastActivity: 0, numReplies: 0, state: 0, lockReason: null },
			attendee: { notificationLevel: 0 },
			canManage: true,
			first: null,
			last: null,
		}

		it('adopts the server response into the store, not the request (AD-13)', async () => {
			// Arrange
			const responseThreadInfo = { ...baseThreadInfo, thread: { ...baseThreadInfo.thread, state: 1 } }
			setThreadState.mockResolvedValueOnce({ data: { ocs: { data: responseThreadInfo } } })

			// Act
			await chatExtrasStore.changeThreadState(token, 1, 1)

			// Assert
			expect(setThreadState).toHaveBeenCalledWith(token, 1, 1, undefined)
			expect(chatExtrasStore.getThread(token, 1).thread.state).toBe(1)
		})

		it('passes an optional reason through to the API call (AC10)', async () => {
			// Arrange
			setThreadState.mockResolvedValueOnce({ data: { ocs: { data: { ...baseThreadInfo, thread: { ...baseThreadInfo.thread, state: 2 } } } } })

			// Act
			await chatExtrasStore.changeThreadState(token, 1, 2, 'Repeated off-topic discussion')

			// Assert
			expect(setThreadState).toHaveBeenCalledWith(token, 1, 2, 'Repeated off-topic discussion')
		})

		it('does not update the store when the API call fails', async () => {
			// Arrange
			chatExtrasStore.addThread(token, baseThreadInfo)
			// mockImplementationOnce (rather than mockRejectedValueOnce)
			// creates the rejected promise lazily, only when setThreadState()
			// is actually invoked, so the store's own await/catch attaches
			// to it in the same tick - avoiding a false-positive unhandled
			// rejection warning from the eagerly-created alternative.
			setThreadState.mockImplementationOnce(() => Promise.reject(new Error('failed')))
			// test-setup.js makes console.error throw ("make test fail on
			// errors or warnings"); changeThreadState() logs the caught
			// error the same way renameThread() already does, so this
			// expected, handled error must be silenced locally.
			const consoleErrorMock = vi.spyOn(console, 'error').mockImplementation(() => {})

			// Act
			await chatExtrasStore.changeThreadState(token, 1, 1)

			// Assert - the stored thread still reports the pre-request state
			expect(chatExtrasStore.getThread(token, 1).thread.state).toBe(0)
			expect(consoleErrorMock).toHaveBeenCalled()
			consoleErrorMock.mockRestore()
		})

		/**
		 * Presses one of the dialog's buttons, which is the only thing that
		 * distinguishes a confirmation from a dismissal: ConfirmDialog resolves
		 * an isForm dialog with the input value however it was closed.
		 *
		 * @param {number} index button to press, or -1 to close without pressing one
		 * @param {*} resolved what the dialog promise resolves with
		 */
		function mockDialog(index, resolved) {
			spawnDialog.mockImplementationOnce((component, props) => {
				if (index >= 0) {
					props.buttons[index].callback()
				}
				return Promise.resolve(resolved)
			})
		}

		it('prompts for a lock reason using the published capability bound (AC11: the bound is one server-side constant, not restated in the interface)', async () => {
			// Arrange
			getTalkConfig.mockReturnValueOnce(4000)
			mockDialog(1, 'Repeated off-topic discussion')

			// Act
			const reason = await chatExtrasStore.promptLockThreadReason()

			// Assert
			expect(getTalkConfig).toHaveBeenCalledWith('local', 'threads', 'lock-reason-length')
			expect(reason).toBe('Repeated off-topic discussion')
		})

		it('treats a confirmed empty field as no reason, not an error (AC11)', async () => {
			// Arrange
			getTalkConfig.mockReturnValueOnce(4000)
			mockDialog(1, '')

			// Act
			const reason = await chatExtrasStore.promptLockThreadReason()

			// Assert - an empty string locks with no reason
			expect(reason).toBe('')
		})

		it('reports a dismissal distinctly from an empty reason, so backing out does not lock the thread', async () => {
			// Arrange - ConfirmDialog resolves an isForm dialog with the input
			// value even when dismissed, so the resolved value cannot be trusted
			getTalkConfig.mockReturnValueOnce(4000)
			mockDialog(0, 'half-typed reason')

			// Act
			const reason = await chatExtrasStore.promptLockThreadReason()

			// Assert
			expect(reason).toBeNull()
		})

		it('reports Escape and click-outside as a dismissal too (no button pressed)', async () => {
			// Arrange
			getTalkConfig.mockReturnValueOnce(4000)
			mockDialog(-1, '')

			// Act
			const reason = await chatExtrasStore.promptLockThreadReason()

			// Assert
			expect(reason).toBeNull()
		})
	})

	describe('initiateEditingMessage', () => {
		it('should set the message ID to edit, set the chat edit input, and emit an event', () => {
			// Arrange
			const payload = {
				token: 'token-1',
				id: 'id-1',
				message: 'Hello, world!',
				messageParameters: {},
			}
			const emitSpy = vi.spyOn(EventBus, 'emit')

			// Act
			chatExtrasStore.initiateEditingMessage(payload)

			// Assert
			expect(chatExtrasStore.getMessageIdToEdit('token-1')).toBe('id-1')
			expect(chatExtrasStore.getChatEditInput('token-1')).toEqual('Hello, world!')
			expect(emitSpy).toHaveBeenCalledWith('editing-message')
		})

		it('should set the chat edit input text to empty if the message is a file share only', () => {
			// Arrange
			const payload = {
				token: 'token-1',
				id: 'id-1',
				message: '{file}',
				messageParameters: { file0: 'file-path' },
			}

			// Act
			chatExtrasStore.initiateEditingMessage(payload)

			// Assert
			expect(chatExtrasStore.getChatEditInput('token-1')).toEqual('')
		})
	})
})
