<?php

namespace fostercommerce\variantmanager\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use fostercommerce\variantmanager\db\Table;

/**
 * Move each attribute's field layouts into a field set that any attribute can be assigned.
 */
class m260921_220429_field_sets extends Migration
{
	private const OLD_CONFIG_PATH = 'variant-manager.attributes';

	private const NEW_CONFIG_PATH = 'variant-manager.fieldSets';

	/**
	 * @var array<string, true>
	 */
	private array $usedHandles = [];

	public function safeUp(): bool
	{
		$this->addColumn(Table::ATTRIBUTES, 'fieldSetUid', $this->char(36)->null()->after('priceModifier'));
		$this->createIndex(null, Table::ATTRIBUTES, ['fieldSetUid'], false);

		$projectConfig = Craft::$app->getProjectConfig();
		$configs = $projectConfig->get(self::OLD_CONFIG_PATH);
		$storedFieldSets = $projectConfig->get(self::NEW_CONFIG_PATH);

		// A deploy that applies project config before migrating has the field sets already, keyed on the same uid
		if (! is_array($configs)) {
			$configs = $storedFieldSets;
		}

		if (! is_array($configs)) {
			return true;
		}

		// Writing config here skips validation, so reserve what HandleValidator would reject on the next save
		foreach ([...HandleValidator::$baseReservedWords, 'title'] as $reservedWord) {
			$this->usedHandles[$reservedWord] = true;
		}

		// An aborted run leaves field sets whose handles this run must not reuse
		foreach (is_array($storedFieldSets) ? $storedFieldSets : [] as $storedFieldSet) {
			$storedHandle = $storedFieldSet['handle'] ?? null;
			if (is_string($storedHandle)) {
				$this->usedHandles[$storedHandle] = true;
			}
		}

		$canonicalsByUid = (new Query())
			->select(['elements.uid', 'elements.id', 'attributes.name'])
			->from([
				'attributes' => Table::ATTRIBUTES,
			])
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[attributes.id]]')
			->where([
				'attributes.attributeId' => 0,
				'elements.draftId' => null,
				'elements.revisionId' => null,
			])
			->indexBy('uid')
			->all();

		foreach ($configs as $attributeUid => $config) {
			$canonical = $canonicalsByUid[$attributeUid] ?? null;

			if (! is_array($canonical)) {
				continue;
			}

			$name = (string) $canonical['name'];

			// The field set reuses the attribute uid, so a read-only install maps records without writing config
			// Update the drafts and revisions too, because applying a draft writes its row back over the canonical record
			$this->update(Table::ATTRIBUTES, [
				'fieldSetUid' => $attributeUid,
			], [
				'id' => (new Query())
					->select(['id'])
					->from(CraftTable::ELEMENTS)
					->where([
						'or',
						[
							'id' => $canonical['id'],
						],
						[
							'canonicalId' => $canonical['id'],
						],
					]),
			]);
			if ($projectConfig->readOnly) {
				continue;
			}

			if ($projectConfig->get(self::NEW_CONFIG_PATH . '.' . $attributeUid) !== null) {
				continue;
			}

			$projectConfig->set(self::NEW_CONFIG_PATH . '.' . $attributeUid, [
				'name' => $name,
				'handle' => $this->uniqueHandle($name),
				'fieldLayouts' => $this->normalizeLayouts($config['fieldLayouts'] ?? []),
				'optionFieldLayouts' => $this->normalizeLayouts($config['optionFieldLayouts'] ?? []),
			], "Move the “{$name}” variant attribute settings into a field set");
		}

		if (! $projectConfig->readOnly) {
			$projectConfig->remove(self::OLD_CONFIG_PATH, 'Remove the per-attribute variant attribute settings');
		}

		return true;
	}

	/**
	 * Two attribute names can reduce to one handle, since nameKey only lowercases and trims.
	 */
	private function uniqueHandle(string $name): string
	{
		$base = StringHelper::toHandle($name);
		if ($base === '') {
			// Use a fixed handle, since a name with no letters reduces to an empty string
			$base = 'fieldSet';
		}

		$handle = $base;
		$suffix = 1;

		while (isset($this->usedHandles[$handle])) {
			$handle = $base . ++$suffix;
		}

		$this->usedHandles[$handle] = true;

		return $handle;
	}

	/**
	 * Round-trip each layout, because the stored config has no tab uid until getConfig() writes one.
	 *
	 * @param array<string, mixed> $layouts
	 * @return array<string, mixed>
	 */
	private function normalizeLayouts(array $layouts): array
	{
		return array_map(
			static fn (mixed $layout): array => FieldLayout::createFromConfig((array) $layout)->getConfig() ?? [],
			$layouts
		);
	}
}
