<?php

declare(strict_types=1);

namespace App\Service\EngineScaling;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * The engine was asked to start but is not answering yet. Recoverable: Messenger redelivers the
 * message after the delay WITHOUT counting it against max_retries, so a slow model load never
 * pushes a batch to the failure transport.
 */
final class EngineNotReadyException extends RecoverableMessageHandlingException
{
}
