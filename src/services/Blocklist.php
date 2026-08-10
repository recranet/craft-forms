<?php

namespace recranet\forms\services;

use Craft;
use recranet\forms\elements\Submission;
use recranet\forms\models\Form;
use recranet\forms\Plugin;
use recranet\forms\records\BlocklistRecord;
use yii\base\Component;

/**
 * The editor-managed sender blocklist.
 *
 * Settings::$blocklist covers the same job, but it is project config: on
 * every environment with `allowAdminChanges` off it is read-only, which is
 * precisely where editors sit triaging spam. So there are two lists —
 * config for infrastructure-level entries (deploy-managed, env vars) and
 * this table for the ones an editor adds from a submission. SpamService
 * checks the union; entry shapes and matching rules are identical, so
 * nothing new had to be invented for the second list.
 *
 * Blocklist entries deliberately OUTLIVE the submission they came from:
 * retention pruning removes messages, but a block is an abuse measure, not
 * correspondence. `submissionId` therefore nulls rather than cascades.
 */
class Blocklist extends Component
{
	/**
	 * Every stored pattern, lowercased and trimmed — the shape
	 * SpamService::checkBlocklist() compares against.
	 *
	 * @return string[]
	 */
	public function getPatterns(): array
	{
		$patterns = BlocklistRecord::find()
			->select(['pattern'])
			->column();

		return array_values(array_filter(array_map(
			fn($pattern) => mb_strtolower(trim((string)$pattern)),
			$patterns,
		)));
	}

	/**
	 * All entries, newest first, for the management screen.
	 *
	 * @return BlocklistRecord[]
	 */
	public function getAllEntries(): array
	{
		return BlocklistRecord::find()->orderBy(['dateCreated' => SORT_DESC])->all();
	}

	/**
	 * Add a pattern. Returns false only when the pattern is empty — an
	 * already-blocked sender counts as success, since the caller's intent
	 * ("this sender is blocked") is satisfied either way.
	 */
	public function add(string $pattern, ?string $note = null, ?int $submissionId = null): bool
	{
		$pattern = mb_strtolower(trim($pattern));

		if ($pattern === '') {
			return false;
		}

		// The unique index would reject a duplicate; check first so a second
		// click reads as "already blocked" instead of a database error
		if (BlocklistRecord::findOne(['pattern' => $pattern])) {
			return true;
		}

		$record = new BlocklistRecord();
		$record->pattern = $pattern;
		$record->note = $note !== null && trim($note) !== '' ? trim($note) : null;
		$record->addedBy = Craft::$app->getUser()->getId();
		$record->submissionId = $submissionId;

		if (!$record->save()) {
			Plugin::error("Could not add \"{$pattern}\" to the blocklist: " . implode('; ', $record->getFirstErrors()));

			return false;
		}

		return true;
	}

	public function remove(int $id): bool
	{
		$record = BlocklistRecord::findOne($id);

		return $record ? (bool)$record->delete() : false;
	}

	/**
	 * Whether a pattern is already blocked by EITHER list, so the CP can
	 * show "already blocked" instead of offering the same action again.
	 */
	public function has(string $pattern): bool
	{
		$pattern = mb_strtolower(trim($pattern));

		return $pattern !== '' && in_array($pattern, $this->allPatterns(), true);
	}

	/**
	 * Config list + stored list, deduplicated. This is what the spam
	 * pipeline matches against.
	 *
	 * @return string[]
	 */
	public function allPatterns(): array
	{
		return array_values(array_unique(array_merge(
			Plugin::getInstance()->getSettings()->getBlocklist(),
			$this->getPatterns(),
		)));
	}

	/**
	 * The sender address of a submission — the value the "block this
	 * sender" action offers. Null when the form has no email field or the
	 * value is gone (an anonymized submission).
	 */
	public function senderAddress(Form $form, Submission $submission): ?string
	{
		$handle = $form->getEmailFieldHandle();

		if (!$handle) {
			return null;
		}

		$email = mb_strtolower(trim((string)$submission->value($handle)));

		return $email !== '' ? $email : null;
	}

	/**
	 * The "@domain" form of an address, for blocking a whole domain rather
	 * than one mailbox. Null when the address has no domain part.
	 */
	public function senderDomain(string $email): ?string
	{
		$parts = explode('@', mb_strtolower(trim($email)));

		return count($parts) === 2 && $parts[1] !== '' ? '@' . $parts[1] : null;
	}
}
