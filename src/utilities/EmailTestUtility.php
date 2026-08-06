<?php

namespace recranet\forms\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\App;

/**
 * Control panel utility that verifies the SMTP connection and sends a test
 * email, exposing the underlying transport errors that Craft's mailer
 * normally swallows.
 *
 * Lives under Utilities so it also works on environments where
 * allowAdminChanges is disabled (Settings → Email is unavailable there).
 */
class EmailTestUtility extends Utility
{
	public static function displayName(): string
	{
		return 'Email / SMTP test';
	}

	public static function id(): string
	{
		return 'recranet-forms-email-test';
	}

	public static function icon(): ?string
	{
		return 'envelope';
	}

	public static function contentHtml(): string
	{
		$mailSettings = App::mailSettings();

		return Craft::$app->getView()->renderTemplate('recranet-forms/utilities/email-test.twig', [
			'transportType' => $mailSettings->transportType,
			'transportSettings' => $mailSettings->transportSettings ?? [],
			'fromEmail' => App::parseEnv($mailSettings->fromEmail),
			'currentUserEmail' => Craft::$app->getUser()->getIdentity()?->email,
		]);
	}
}
