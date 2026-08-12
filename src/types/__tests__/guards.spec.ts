/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { isThreadNotFoundError } from '../guards.ts'

/**
 * Builds a minimal axios-error-shaped object matching the OCS response
 * envelope (`response.data.ocs.data.error`).
 *
 * @param status - HTTP status code
 * @param error - the `error` value the OCS payload carries, if any
 */
function makeOcsError(status: number, error?: string) {
	return {
		response: {
			status,
			data: {
				ocs: {
					data: error !== undefined ? { error } : {},
				},
			},
		},
	}
}

describe('isThreadNotFoundError (Story 1.9, AC4)', () => {
	it('returns true for the exact "Thread not found" shape', () => {
		expect(isThreadNotFoundError(makeOcsError(404, 'thread'))).toBe(true)
	})

	it('returns false for a 404 with a different error identifier', () => {
		expect(isThreadNotFoundError(makeOcsError(404, 'actor'))).toBe(false)
	})

	it('returns false for a 404 with no error identifier at all', () => {
		expect(isThreadNotFoundError(makeOcsError(404))).toBe(false)
	})

	it('returns false for a non-404 status with the "thread" identifier', () => {
		expect(isThreadNotFoundError(makeOcsError(400, 'thread'))).toBe(false)
	})

	it('returns false for a cancelled request (no response property)', () => {
		expect(isThreadNotFoundError(new Error('canceled'))).toBe(false)
	})

	it('returns false for null or undefined', () => {
		expect(isThreadNotFoundError(null)).toBe(false)
		expect(isThreadNotFoundError(undefined)).toBe(false)
	})

	it('returns false for a response missing the ocs envelope', () => {
		expect(isThreadNotFoundError({ response: { status: 404, data: {} } })).toBe(false)
	})
})
