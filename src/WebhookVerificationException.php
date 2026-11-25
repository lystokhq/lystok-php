<?php

declare(strict_types=1);

namespace Lystok;

/** A webhook whose signature or timestamp is wrong: answer it with 401 and do not trust it. */
final class WebhookVerificationException extends \RuntimeException
{
}
