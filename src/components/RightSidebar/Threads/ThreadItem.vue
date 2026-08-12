<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RouteLocationAsRelative } from 'vue-router'
import type {
	ThreadInfo,
} from '../../../types/index.ts'

import { t } from '@nextcloud/l10n'
import { usernameToColor } from '@nextcloud/vue/functions/usernameToColor'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconArrowLeftTop from 'vue-material-design-icons/ArrowLeftTop.vue'
import IconBellOutline from 'vue-material-design-icons/BellOutline.vue'
import IconForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import IconLockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import IconLockOutline from 'vue-material-design-icons/LockOutline.vue'
import IconPencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import ThreadStateBadge from './ThreadStateBadge.vue'
import { AVATAR, THREAD } from '../../../constants.ts'
import { useChatExtrasStore } from '../../../stores/chatExtras.ts'
import { getDisplayNameWithFallback } from '../../../utils/getDisplayName.ts'
import { parseToSimpleMessage } from '../../../utils/textParse.ts'
import { notificationLevelIcons, notificationLevels } from './threadsConstants.ts'

const { thread, showManagementActions = false } = defineProps<{
	thread: ThreadInfo
	/**
	 * Story 1.8, AC11: whether this row offers the four lifecycle-transition
	 * actions. Scoped to the "threads list" surface (`ThreadsTab.vue`) only
	 * - the surface Epic 2 later replaces with the Directory - and not to
	 * the cross-Conversation Followed Thread List (`LeftSidebar.vue`),
	 * which omits this prop and keeps today's behaviour.
	 */
	showManagementActions?: boolean
}>()

const router = useRouter()
const route = useRoute()

const chatExtrasStore = useChatExtrasStore()

const submenu = ref<string | null>(null)

const lastActivity = computed(() => thread.thread.lastActivity * 1000)
const subname = computed(() => {
	const threadMessage = thread.last ?? thread.first
	if (!threadMessage) {
		return t('spreed', 'No messages')
	}

	const actor = getDisplayNameWithFallback(threadMessage.actorDisplayName, threadMessage.actorType, true)
	const lastMessage = parseToSimpleMessage(threadMessage.message, threadMessage.messageParameters)

	return t('spreed', '{actor}: {lastMessage}', { actor, lastMessage }, {
		escape: false,
		sanitize: false,
	})
})

const to = computed<RouteLocationAsRelative>(() => {
	return {
		name: 'conversation',
		params: { token: thread.thread.roomToken },
		query: { threadId: thread.thread.id },
	}
})

const active = computed(() => {
	return route.fullPath.startsWith(router.resolve(to.value).fullPath)
})

const timeFormat = computed<Intl.DateTimeFormatOptions>(() => {
	if (new Date().toDateString() === new Date(lastActivity.value).toDateString()) {
		return { timeStyle: 'short' }
	}
	return { dateStyle: 'short' }
})

const threadNotificationLabel = computed(() => notificationLevels.find((l) => l.value === thread.attendee.notificationLevel)?.label)

// Story 1.3, AC6: the one computed getter every management control reads -
// the server's authority answer, not a locally re-derived permission check.
const canManageThread = computed(() => chatExtrasStore.canManageThread(thread.thread.roomToken, thread.thread.id))

/**
 * Renames the thread
 */
async function renameThreadTitle() {
	await chatExtrasStore.renameThread(thread.thread.roomToken, thread.thread.id)
}

/**
 * Resets the submenu when the actions menu is closed
 *
 * @param open - actions menu state
 */
function handleActionsMenuOpen(open: boolean) {
	if (!open) {
		submenu.value = null
	}
}

// Story 1.8, AC11/AC12: the row-menu equivalent of ThreadHeader.vue's
// four lifecycle-transition actions (Story 1.4 AC17), so a Thread Manager
// closes or locks a Thread without opening it. `changeThreadState()`
// replaces the store's entry with the server's response (Story 1.4
// AD-13), so the row's badge updates in place - nothing here navigates.

/**
 * Close the thread (Ongoing -> Closed), from the row menu.
 */
async function closeThread() {
	await chatExtrasStore.changeThreadState(thread.thread.roomToken, thread.thread.id, THREAD.STATE.CLOSED)
}

/**
 * Reopen the thread (Closed -> Ongoing) or unlock it (Locked -> Ongoing) -
 * both land on the same target state (mirrors ThreadHeader.vue).
 */
async function reopenThread() {
	await chatExtrasStore.changeThreadState(thread.thread.roomToken, thread.thread.id, THREAD.STATE.ONGOING)
}

/**
 * Lock the thread, from either Ongoing or Closed, with an optional reason.
 */
async function lockThread() {
	const reason = await chatExtrasStore.promptLockThreadReason()
	await chatExtrasStore.changeThreadState(thread.thread.roomToken, thread.thread.id, THREAD.STATE.LOCKED, reason)
}
</script>

<template>
	<NcListItem
		:data-nav-id="`thread_${thread.thread.id}`"
		class="thread"
		:name="thread.thread.title"
		:to="to"
		:active="active"
		forceMenu
		@update:menuOpen="handleActionsMenuOpen">
		<template #icon>
			<div
				class="thread__icon"
				:style="{ '--color-thread-icon': usernameToColor(thread.thread.title).color }">
				<IconForumOutline :size="0.6 * AVATAR.SIZE.DEFAULT" />
			</div>
		</template>
		<template #name>
			<span>{{ thread.thread.title }}</span>
		</template>
		<template #subname>
			{{ subname }}
		</template>
		<template #actions>
			<template v-if="submenu === null">
				<NcActionButton
					v-if="canManageThread"
					key="rename-thread"
					closeAfterClick
					@click="renameThreadTitle">
					<template #icon>
						<IconPencilOutline :size="20" />
					</template>
					{{ t('spreed', 'Edit thread details') }}
				</NcActionButton>
				<NcActionButton
					key="show-notifications"
					isMenu
					:description="threadNotificationLabel"
					@click="submenu = 'notifications'">
					<template #icon>
						<IconBellOutline :size="20" />
					</template>
					{{ t('spreed', 'Thread notifications') }}
				</NcActionButton>
				<!-- Story 1.8, AC11/AC12: same four transitions as
					ThreadHeader.vue (Story 1.4 AC17), gated on
					showManagementActions (this surface only) and
					canManageThread (absent, not disabled, for a
					non-manager). Which of them show depends on the
					row's current state, matching ThreadHeader.vue's
					mapping exactly. -->
				<NcActionButton
					v-if="showManagementActions && canManageThread && thread.thread.state === THREAD.STATE.ONGOING"
					key="close-thread"
					closeAfterClick
					@click="closeThread">
					<template #icon>
						<IconArchiveOutline :size="20" />
					</template>
					{{ t('spreed', 'Close thread') }}
				</NcActionButton>
				<NcActionButton
					v-if="showManagementActions && canManageThread && thread.thread.state === THREAD.STATE.CLOSED"
					key="reopen-thread"
					closeAfterClick
					@click="reopenThread">
					<template #icon>
						<IconLockOpenOutline :size="20" />
					</template>
					{{ t('spreed', 'Reopen thread') }}
				</NcActionButton>
				<NcActionButton
					v-if="showManagementActions && canManageThread && (thread.thread.state === THREAD.STATE.ONGOING || thread.thread.state === THREAD.STATE.CLOSED)"
					key="lock-thread"
					closeAfterClick
					@click="lockThread">
					<template #icon>
						<IconLockOutline :size="20" />
					</template>
					{{ t('spreed', 'Lock thread') }}
				</NcActionButton>
				<NcActionButton
					v-if="showManagementActions && canManageThread && thread.thread.state === THREAD.STATE.LOCKED"
					key="unlock-thread"
					closeAfterClick
					@click="reopenThread">
					<template #icon>
						<IconLockOpenOutline :size="20" />
					</template>
					{{ t('spreed', 'Unlock thread') }}
				</NcActionButton>
			</template>
			<template v-else-if="submenu === 'notifications'">
				<NcActionButton
					key="action-back"
					:aria-label="t('spreed', 'Back')"
					@click.stop="submenu = null">
					<template #icon>
						<IconArrowLeft class="bidirectional-icon" :size="20" />
					</template>
					{{ t('spreed', 'Back') }}
				</NcActionButton>

				<NcActionSeparator />

				<NcActionButton
					v-for="level in notificationLevels"
					:key="level.value"
					:modelValue="thread.attendee.notificationLevel.toString()"
					:value="level.value.toString()"
					:description="level.description"
					type="radio"
					@click="chatExtrasStore.setThreadNotificationLevel(thread.thread.roomToken, thread.thread.id, level.value)">
					<template #icon>
						<component :is="notificationLevelIcons[level.value]" :size="20" />
					</template>
					{{ level.label }}
				</NcActionButton>
			</template>
		</template>
		<template #details>
			<span class="thread__details">
				<ThreadStateBadge :state="thread.thread.state" />
				<span class="thread__details-replies">
					<IconArrowLeftTop class="bidirectional-icon" :size="16" />
					{{ thread.thread.numReplies }}
				</span>
				<NcDateTime
					:timestamp="lastActivity"
					:format="timeFormat"
					:relativeTime="false"
					ignoreSeconds />
			</span>
		</template>
	</NcListItem>
</template>

<style lang="scss" scoped>
.thread {
	:deep(.list-item-content__name) {
		font-size: var(--font-size-small);
		font-weight: 400;
		color: var(--color-text-maxcontrast);
	}

	:deep(.list-item-content__subname) {
		color: var(--color-main-text);
	}

	&__icon {
		--mixed-color: color-mix(in srgb, var(--color-thread-icon) 10%, var(--color-main-background));
		width: 40px; // AVATAR.SIZE.DEFAULT
		height: 40px;
		display: flex;
		justify-content: center;
		align-items: center;
		border-radius: 50%;
		color: var(--color-thread-icon);
		background-color: var(--mixed-color, var(--color-background-dark));
	}

	&__details {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		font-size: var(--font-size-small);

		&-replies {
			display: flex;
			gap: calc(0.5 * var(--default-grid-baseline));
			padding-inline: calc(2 * var(--default-grid-baseline));
			border-radius: var(--border-radius-pill);
			background-color: var(--color-primary-element-light);
			color: var(--color-main-text);
			font-weight: 600;
		}
	}

	&.list-item__wrapper--active .thread__details-replies {
		background-color: var(--color-primary-element-light-hover);
	}
}
</style>
