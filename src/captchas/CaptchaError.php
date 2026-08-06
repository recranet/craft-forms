<?php

namespace recranet\forms\captchas;

/**
 * A captcha could not be verified for reasons unrelated to the visitor:
 * missing/invalid keys, a domain that isn't allowlisted, or an unreachable
 * verification API.
 *
 * This is a real error — it must be shown to the user and logged/reported —
 * and must never cause a submission to be classified as spam.
 */
class CaptchaError extends \Exception
{
}
