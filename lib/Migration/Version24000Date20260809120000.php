<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCA\Talk\Model\Thread;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Add the Thread lifecycle state column (Ongoing/Closed/Locked).
 *
 * Additive and defaulted per AD-19: every pre-existing Thread reads as
 * Ongoing because the schema default does the work at ALTER TABLE time -
 * this migration never runs an UPDATE statement to backfill existing rows.
 */
class Version24000Date20260809120000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('talk_threads');
		if (!$table->hasColumn('state')) {
			$table->addColumn('state', Types::INTEGER, [
				'notnull' => false,
				'default' => Thread::STATE_ONGOING,
			]);
		}

		return $schema;
	}
}
