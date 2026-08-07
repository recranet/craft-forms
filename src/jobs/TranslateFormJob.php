<?php

namespace recranet\forms\jobs;

use Craft;
use craft\queue\BaseJob;
use recranet\forms\Plugin;

/**
 * Translates one form's content into one target site, in the background.
 *
 * Queued rather than run inline because a form with a dozen fields is a real
 * LLM round trip: too slow for a CP request, and a browser timeout halfway
 * through would leave the editor with no idea whether it succeeded. Failures
 * stay visible in the queue and can be retried from there.
 *
 * One job per target site, so translating into four languages queues four jobs
 * and a single failing language doesn't take the other three down with it.
 */
class TranslateFormJob extends BaseJob
{
	/**
	 * Form to translate.
	 */
	public int $formId;

	/**
	 * Site to translate the form's content into.
	 */
	public int $targetSiteId;

	public function execute($queue): void
	{
		// Progress is coarse on purpose: the whole translation is a single
		// batch API call, so there is nothing meaningful to report between
		// "started" and "done" — this only keeps the queue widget honest.
		$this->setProgress($queue, 0);

		Plugin::getInstance()->formTranslations->translateWithAi($this->formId, $this->targetSiteId);

		$this->setProgress($queue, 1);
	}

	protected function defaultDescription(): ?string
	{
		$form = Plugin::getInstance()->forms->getFormById($this->formId);
		$targetSite = Craft::$app->getSites()->getSiteById($this->targetSiteId);

		return Craft::t('recranet-forms', 'Translating form “{form}” into {site}', [
			'form' => $form?->name ?? $this->formId,
			'site' => $targetSite?->name ?? $this->targetSiteId,
		]);
	}
}
