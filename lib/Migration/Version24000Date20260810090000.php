<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Talk\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

/**
 * Add the Thread lock-reason column (Story 1.4, FR-43).
 *
 * Additive and nullable per AD-19: no default is needed (NULL is the
 * correct "no reason" value for both pre-existing and never-locked
 * Threads), and this migration never runs an UPDATE statement to
 * backfill existing rows.
 */
class Version24000Date20260810090000 extends SimpleMigrationStep {
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
		if (!$table->hasColumn('lock_reason')) {
			$table->addColumn('lock_reason', Types::TEXT, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
