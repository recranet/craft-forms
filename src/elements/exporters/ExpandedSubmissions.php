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
		return 'Submissions (expanded fields)';
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

				$fields[$row['handle']] = $value;
				$fieldKeys[$row['handle']] = true;
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

		// Normalize: every row gets every field column, in a stable order
		$fieldKeys = array_keys($fieldKeys);

		return array_map(function (array $row) use ($fieldKeys) {
			$fields = $row['_fields'];
			unset($row['_fields']);

			foreach ($fieldKeys as $key) {
				// Prefix to avoid collisions with the fixed columns
				$row["field:$key"] = $fields[$key] ?? '';
			}

			return $row;
		}, $rows);
	}
}
