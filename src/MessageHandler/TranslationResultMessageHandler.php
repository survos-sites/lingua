<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TranslationWorker\MalformedTranslationResultMessage;
use App\Message\TranslationWorker\TranslationResultMessage;
use App\Service\TranslationWorker\TranslationResultProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Worker results, from the dedicated `translation_results` AMQP transport only. */
final class TranslationResultMessageHandler
{
    public function __construct(private readonly TranslationResultProcessor $processor)
    {
    }

    #[AsMessageHandler]
    public function result(TranslationResultMessage $message): void
    {
        $this->processor->process($message);
    }

    #[AsMessageHandler]
    public function malformed(MalformedTranslationResultMessage $message): void
    {
        // Retrying cannot fix a malformed body; park it in the Doctrine failed transport.
        throw new UnrecoverableMessageHandlingException('Malformed translation.result: '.$message->reason);
    }
}
