<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="thread-header"
		:class="{ 'thread-header--standalone': standalone }">
		<NcButton
			v-if="standalone"
			:title="t('spreed', 'Back')"
			:aria-label="t('spreed', 'Back')"
			@click="threadId = 0">
			<template #icon>
				<IconArrowLeft class="bidirectional-icon" :size="20" />
			</template>
		</NcButton>
		<IconChevronRight
			v-else
			class="bidirectional-icon"
			:size="20" />

		<div v-if="currentThread" class="conversation-header">
			<div
				class="conversation-header__thread-icon"
				:style="{ '--color-thread-icon': usernameToColor(currentThread.thread.title).color }">
				<IconForumOutline :size="20" />
			</div>
			<div class="conversation-header__text">
				<p class="title">
					{{ currentThread.thread.title }}
				</p>
				<p class="description">
					{{ n('spreed', '%n reply', '%n replies', currentThread.thread.numReplies) }}
				</p>
				<!-- Story 1.8, AC2: state, who set it, and (when Locked)
					the reason - absent entirely for Ongoing, matching
					AC1's "common case stays quiet" principle. -->
				<p v-if="stateSummary" class="description description--state">
					<ThreadStateBadge :state="threadState" />
					{{ stateSummary }}
				</p>
			</div>
		</div>

		<NcActions
			:aria-label="t('spreed', 'Thread notifications')"
			:title="t('spreed', 'Thread notifications')"
			:variant="threadNotificationVariant">
			<template #icon>
				<component :is="notificationLevelIcons[threadNotification]" :size="20" />
			</template>
			<NcActionButton
				v-for="level in notificationLevels"
				:key="level.value"
				:modelValue="threadNotification.toString()"
				:value="level.value.toString()"
				:description="level.description"
				type="radio"
				closeAfterClick
				@click="chatExtrasStore.setThreadNotificationLevel(token, threadId, level.value)">
				<template #icon>
					<component :is="notificationLevelIcons[level.value]" :size="20" />
				</template>
				{{ level.label }}
			</NcActionButton>
		</NcActions>

		<NcActions
			v-if="canManageThread"
			:aria-label="t('spreed', 'Thread actions')"
			:title="t('spreed', 'Thread actions')"
			forceMenu>
			<NcActionButton
				key="rename-thread"
				closeAfterClick
				@click="renameThreadTitle">
				<template #icon>
					<IconPencilOutline :size="20" />
				</template>
				{{ t('spreed', 'Edit thread details') }}
			</NcActionButton>
			<!-- Story 1.4, AC17: all four lifecycle transitions, offered
				from the thread itself, gated on the same canManageThread
				(AC18) as the rename action above. Which of them show
				depends on the thread's current state (AC1-AC4). -->
			<NcActionButton
				v-if="threadState === THREAD.STATE.ONGOING"
				key="close-thread"
				closeAfterClick
				@click="closeThread">
				<template #icon>
					<IconArchiveOutline :size="20" />
				</template>
				{{ t('spreed', 'Close thread') }}
			</NcActionButton>
			<NcActionButton
				v-if="threadState === THREAD.STATE.CLOSED"
				key="reopen-thread"
				closeAfterClick
				@click="reopenThread">
				<template #icon>
					<IconLockOpenOutline :size="20" />
				</template>
				{{ t('spreed', 'Reopen thread') }}
			</NcActionButton>
			<NcActionButton
				v-if="threadState === THREAD.STATE.ONGOING || threadState === THREAD.STATE.CLOSED"
				key="lock-thread"
				closeAfterClick
				@click="lockThread">
				<template #icon>
					<IconLockOutline :size="20" />
				</template>
				{{ t('spreed', 'Lock thread') }}
			</NcActionButton>
			<NcActionButton
				v-if="threadState === THREAD.STATE.LOCKED"
				key="unlock-thread"
				closeAfterClick
				@click="reopenThread">
				<template #icon>
					<IconLockOpenOutline :size="20" />
				</template>
				{{ t('spreed', 'Unlock thread') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { usernameToColor } from '@nextcloud/vue/functions/usernameToColor'
import { computed, watch } from 'vue'
import { useStore } from 'vuex'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import IconForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import IconLockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import IconLockOutline from 'vue-material-design-icons/LockOutline.vue'
import IconPencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import ThreadStateBadge from './ThreadStateBadge.vue'
import { useGetThreadId } from '../../../composables/useGetThreadId.ts'
import { useGetToken } from '../../../composables/useGetToken.ts'
import { PARTICIPANT, THREAD } from '../../../constants.ts'
import { useChatExtrasStore } from '../../../stores/chatExtras.ts'
import { getThreadStateActor, getThreadStateSummary } from '../../../utils/threadState.ts'
import { notificationLevelIcons, notificationLevels } from './threadsConstants.ts'

const props = defineProps<{
	/** Whether component is used outside TopBar */
	standalone?: boolean
}>()

const chatExtrasStore = useChatExtrasStore()
// Story 1.8, AC2: reading message history to derive the state-change
// actor is a different purpose than the authority derivation Story 1.3
// removed Vuex for - it does not reopen that decision.
const store = useStore()
const threadId = useGetThreadId()
const token = useGetToken()

const currentThread = computed(() => chatExtrasStore.getThread(token.value, threadId.value))

const threadNotification = computed(() => currentThread.value?.attendee.notificationLevel ?? PARTICIPANT.NOTIFY.DEFAULT)

const threadNotificationVariant = computed(() => {
	return ([PARTICIPANT.NOTIFY.ALWAYS, PARTICIPANT.NOTIFY.MENTION].includes(threadNotification.value))
		? 'secondary'
		: 'tertiary'
})

// Story 1.3, AC6: the one computed getter every management control reads -
// the server's authority answer, not a locally re-derived permission check.
const canManageThread = computed(() => chatExtrasStore.canManageThread(token.value, threadId.value))

// Story 1.4: which of the four transition actions render depends on this.
const threadState = computed(() => currentThread.value?.thread.state ?? THREAD.STATE.ONGOING)

// Story 1.8, AC2: "who set it" has no persisted field (Story 1.4 AC10 only
// wrote the lock *reason* to a column, explicitly to avoid a history walk -
// not the actor) - derived from the Thread's already-loaded message
// history instead. See threadState.ts and the story's Dev Notes
// "Assumption - deriving 'who set it'" for the reasoning.
const stateActorName = computed(() => getThreadStateActor(store.getters.messagesList(token.value), threadId.value, threadState.value))
const stateSummary = computed(() => getThreadStateSummary(threadState.value, stateActorName.value, currentThread.value?.thread.lockReason ?? null))

watch(currentThread, (value) => {
	if (threadId.value && value === undefined) {
		chatExtrasStore.fetchSingleThread(token.value, threadId.value)
	}
}, { immediate: true })

/**
 * Rename a thread title on server
 */
async function renameThreadTitle() {
	await chatExtrasStore.renameThread(token.value, threadId.value)
}

/**
 * Close the thread (AC1: Ongoing -> Closed)
 */
async function closeThread() {
	await chatExtrasStore.changeThreadState(token.value, threadId.value, THREAD.STATE.CLOSED)
}

/**
 * Reopen the thread (AC3: Closed -> Ongoing) or unlock it (AC4: Locked ->
 * Ongoing) - both transitions land on the same target state.
 */
async function reopenThread() {
	await chatExtrasStore.changeThreadState(token.value, threadId.value, THREAD.STATE.ONGOING)
}

/**
 * Lock the thread, from either Ongoing or Closed (AC2), with an optional
 * reason (AC10, AC11).
 */
async function lockThread() {
	const reason = await chatExtrasStore.promptLockThreadReason()
	await chatExtrasStore.changeThreadState(token.value, threadId.value, THREAD.STATE.LOCKED, reason)
}
</script>

<style lang="scss" scoped>
.thread-header {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	width: 100%;
	gap: var(--default-grid-baseline);

	&--standalone {
		padding: var(--default-grid-baseline);
		border-bottom: 1px solid var(--color-border);
	}
}

.conversation-header {
	position: relative;
	display: flex;
	align-items: center;
	overflow-x: hidden;
	overflow-y: clip;
	white-space: nowrap;
	width: 0;
	flex-grow: 1;
	cursor: pointer;
	&__text {
		display: flex;
		flex-direction:column;
		flex-grow: 1;
		margin-inline-start: 8px;
		justify-content: center;
		width: 100%;
		overflow: hidden;
		// Text is guaranteed to be one line. Make line-height 20px to fit top bar
		line-height: 20px;
		&--offline {
			color: var(--color-text-maxcontrast);
		}
	}
	.title {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.description {
		overflow: hidden;
		text-overflow: ellipsis;
		max-width: fit-content;
		&__in-chat {
			color: var(--color-text-maxcontrast);
		}
		// Story 1.8, AC2/AC10: the badge and summary sit on their own
		// line, reusing the same overflow/ellipsis handling as the
		// reply-count line above so it degrades gracefully in both
		// TopBar.vue's narrow top bar and ChatView.vue's standalone
		// (even narrower) rendering - no sidebar-specific code path.
		&--state {
			display: flex;
			align-items: center;
			gap: calc(1 * var(--default-grid-baseline));
		}
	}

	&__thread-icon {
		--mixed-color: color-mix(in srgb, var(--color-thread-icon) 10%, var(--color-main-background));
		flex-shrink: 0;
		width: var(--default-clickable-area);
		height: var(--default-clickable-area);
		display: flex;
		justify-content: center;
		align-items: center;
		border-radius: 50%;
		color: var(--color-thread-icon);
		background-color: var(--mixed-color, var(--color-background-dark));
	}
}
</style>
