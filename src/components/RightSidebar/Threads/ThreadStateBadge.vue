<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<span
		v-if="state !== THREAD.STATE.ONGOING"
		class="thread-state-badge"
		:title="tooltip"
		:aria-label="tooltip">
		<component :is="icon" :size="16" />
		{{ label }}
	</span>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import IconLockOutline from 'vue-material-design-icons/LockOutline.vue'
import { THREAD } from '../../../constants.ts'

const { state } = defineProps<{
	/** Thread state (THREAD.STATE.ONGOING/CLOSED/LOCKED) */
	state: number
}>()

// Story 1.8, AC1/AC6: an Ongoing Thread renders nothing (the `v-if` above)
// so the common case stays quiet everywhere this component is used, and
// every non-Ongoing state carries both an icon and a text label - colour
// (the pill's tint) is never the only signal.
const icon = computed(() => state === THREAD.STATE.LOCKED ? IconLockOutline : IconArchiveOutline)
const label = computed(() => state === THREAD.STATE.LOCKED ? t('spreed', 'Locked') : t('spreed', 'Closed'))

// Story 1.8, AC5: the Closed indicator must convey that posting still
// works and reopens the thread, mitigating "closed" reading as "shut" the
// way issue trackers use the word. Locked genuinely does refuse writes
// (Stories 1.6/1.7), so no such claim belongs on that tooltip.
const tooltip = computed(() => state === THREAD.STATE.LOCKED
	? t('spreed', 'Locked')
	: t('spreed', 'Closed — posting a new message reopens this thread'))
</script>

<style lang="scss" scoped>
// Story 1.8, AC6: reuses the host theme's own design tokens (the same
// pill pattern ThreadItem.vue's reply-count badge already uses) rather
// than introducing new colour values, so light/dark/high-contrast
// legibility is inherited, not re-derived.
.thread-state-badge {
	display: inline-flex;
	align-items: center;
	gap: calc(0.5 * var(--default-grid-baseline));
	padding-inline: calc(2 * var(--default-grid-baseline));
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-weight: 600;
	white-space: nowrap;
}
</style>
