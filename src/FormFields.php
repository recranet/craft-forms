<?php

namespace recranet\forms;

/**
 * Names of the hidden fields the plugin renders into forms and reads back on
 * submit.
 *
 * Both carry hashed values (Craft's security key), so a bot cannot forge them
 * and a developer cannot accidentally make them visitor-controlled.
 */
final class FormFields
{
	/**
	 * Hashed unix timestamp of the moment the form was rendered, used to reject
	 * submissions that arrived faster than a human could type.
	 */
	public const TIMESTAMP = 'rfFormLoaded';

	/**
	 * Hashed captcha action name the form was rendered with. The server compares
	 * it against the action the provider reports for the token, so a token
	 * harvested from another form — or another site sharing the site key —
	 * cannot be replayed here.
	 */
	public const CAPTCHA_ACTION = 'rfCaptchaAction';
}
