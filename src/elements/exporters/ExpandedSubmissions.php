<?php

namespace recranet\forms\elements\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use recranet\forms\elements\Submission;

/**
 * CSV/JSON export with the submitted values expanded: every form field
 * becomes its own column (labeled via the submit-time snapshot), normalized
 * across all exported submissions.
 */
class ExpandedSubmissions extends ElementExporter
{
	public static function displayName(): string
	{
		return Craft::t('recranet-forms', 'Submissions (expanded fields)');
	}

	public function export(ElementQueryInterface $query): array
	{
		$rows = [];
		$fieldKeys = [];

		/** @var Submission $submission */
		foreach (Db::each($query) as $submission) {
			$fields = [];

			// Layout-only rows (heading, paragraph) are already excluded by getValues()
			foreach ($submission->getValues() as $row) {
				$value = $row['value'];

				if ($row['type'] === 'checkbox' || $row['type'] === 'consent') {
					$value = $value ? 'yes' : 'no';
				} elseif ($row['type'] === 'file') {
					// File value = asset id; export the filename plus its CP url
					$asset = $value && is_numeric($value) ? Craft::$app->getAssets()->getAssetById((int)$value) : null;
					$value = $asset ? $asset->getFilename() . ' (' . $asset->getCpEditUrl() . ')' : '';
				} elseif (is_array($value)) {
					// Multi-value fields (checkboxes) read as a comma-joined list
					$value = implode(', ', array_filter($value, 'is_scalar'));
				} elseif (!is_scalar($value) && $value !== null) {
					$value = json_encode($value);
				}

				// Keyed by uid — the field's identity. Keying by handle would
				// split one field over two columns after a rename, and merge
				// two unrelated fields when a handle gets reused.
				$fields[$row['uid']] = $value;
				$fieldKeys[$row['uid']] = $row['handle'];
			}

			$rows[] = [
				'id' => $submission->id,
				'ref' => $submission->incrementalId,
				'dateCreated' => $submission->dateCreated?->format('Y-m-d H:i:s'),
				'form' => $submission->getForm()?->name,
				'status' => $submission->getStatus(),
				'spamScore' => $submission->spamScore,
				'spamReason' => $submission->spamReason,
				'sourceUrl' => $submission->sourceUrl,
				'_fields' => $fields,
			];
		}

		// Normalize: every row gets every field column, in a stable order.
		// Columns are labeled by handle (the latest one seen per uid); when
		// two different fields ever carried the same handle, the uid keeps
		// them apart in the label instead of silently merging their data.
		$handleCounts = array_count_values($fieldKeys);
		$columns = [];

		foreach ($fieldKeys as $uid => $handle) {
			$columns[$uid] = $handleCounts[$handle] > 1
				? "field:{$handle} ({$uid})"
				: "field:{$handle}";
		}

		return array_map(function (array $row) use ($columns) {
			$fields = $row['_fields'];
			unset($row['_fields']);

			foreach ($columns as $uid => $column) {
				// Prefix to avoid collisions with the fixed columns
				$row[$column] = $fields[$uid] ?? '';
			}

			return $row;
		}, $rows);
	}
}
