<?php

namespace recranet\forms\payments;

/**
 * A payment could not be created or checked due to a configuration or
 * availability problem (bad key, provider unreachable). Same philosophy as
 * CaptchaError: this is OUR problem, never the visitor's — callers surface
 * it as a real error and never lose the submission over it.
 */
class PaymentError extends \Exception
{
}
