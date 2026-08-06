<?php

namespace recranet\forms\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\Smtp;
use craft\web\Controller;
use recranet\forms\Plugin;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use yii\web\Response;

/**
 * Backs the Email / SMTP test utility.
 *
 * Builds the SMTP transport directly (instead of going through Craft's
 * mailer) so connection and authentication failures surface with their full
 * error detail instead of a silent false.
 */
class UtilitiesController extends Controller
{
	public function actionTestEmail(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission('utility:recranet-forms-email-test');

		$recipient = trim((string)Craft::$app->getRequest()->getRequiredBodyParam('recipient'));
		$mailSettings = App::mailSettings();
		$steps = [];

		$adapter = MailerHelper::createTransportAdapter(
			$mailSettings->transportType,
			$mailSettings->transportSettings,
		);

		// Step 1: raw SMTP connection + handshake (incl. auth) when SMTP is used
		if ($adapter instanceof Smtp) {
			$config = $adapter->defineTransport();
			$host = $config['host'] ?? '';
			$port = (int)($config['port'] ?? 0);

			try {
				$transport = new EsmtpTransport($host, $port);

				if (isset($config['username'])) {
					$transport->setUsername((string)$config['username']);
					$transport->setPassword((string)($config['password'] ?? ''));
				}

				$transport->start();
				$transport->stop();

				$steps[] = [
					'label' => 'SMTP connection',
					'success' => true,
					'detail' => sprintf('Connected to %s:%d and completed the handshake.', $host, $port ?: 25),
				];
			} catch (\Throwable $e) {
				$steps[] = [
					'label' => 'SMTP connection',
					'success' => false,
					'detail' => sprintf('%s: %s', get_class($e), $e->getMessage()),
				];
				Plugin::error('SMTP connection test failed: ' . $e->getMessage());
			}
		} else {
			$steps[] = [
				'label' => 'Transport',
				'success' => true,
				'detail' => sprintf('Mail transport is %s — no SMTP connection to test.', $adapter::displayName()),
			];
		}

		// Step 2: send an actual test email through Craft's mailer
		try {
			$sent = Craft::$app->getMailer()
				->compose()
				->setTo($recipient)
				->setSubject('Recranet Forms test email')
				->setTextBody('This is a test email sent from the Recranet Forms email test utility.')
				->send();

			$steps[] = [
				'label' => 'Test email',
				'success' => $sent,
				'detail' => $sent
					? sprintf('Test email sent to %s.', $recipient)
					: 'The mailer reported a transport error — see the SMTP connection step and the Craft logs.',
			];
		} catch (\Throwable $e) {
			$steps[] = [
				'label' => 'Test email',
				'success' => false,
				'detail' => sprintf('%s: %s', get_class($e), $e->getMessage()),
			];
			Plugin::error('Test email failed: ' . $e->getMessage());
		}

		return $this->asJson(['steps' => $steps]);
	}
}
