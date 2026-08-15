/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AxiosError } from '@nextcloud/axios'
import type { components } from './openapi/openapi.ts'

export type ApiErrorResponse<T = null> = AxiosError<{
	ocs: {
		meta: components['schemas']['OCSMeta']
		data: T
	}
}>

/**
 * Check whether caught error is from OCS API
 *
 * @param exception - exception (from catch block) to be verified.
 * Expected to be an AxiosError with OCS response structure, data property is optional and can vary (usually 'null')
 */
export function isAxiosErrorResponse<T = null>(exception: unknown): exception is ApiErrorResponse<T> {
	return exception !== null && typeof exception === 'object' && 'response' in exception
}

/**
 * Whether a caught exception is the OCS "Thread not found" 404 response
 * (Story 1.9, AC4) - the identifier `ChatController::getMessageContext()`
 * and `::receiveMessages()` both already return (`DataResponse(['error' =>
 * 'thread'], Http::STATUS_NOT_FOUND)`) when a `threadId` no longer
 * validates (deleted, expired, or never existed).
 *
 * @param exception - exception (from catch block) to be verified
 */
export function isThreadNotFoundError(exception: unknown): boolean {
	return isAxiosErrorResponse<{ error?: string }>(exception)
		&& exception.response?.status === 404
		&& exception.response?.data?.ocs?.data?.error === 'thread'
}
