<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TranslationNotifier;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('lingua:notifications:flush', 'Publish one bounded page of committed results for one subscriber')]
final readonly class TranslationNotificationsFlushCommand
{
    public function __construct(private TranslationNotifier $notifier) {}

    public function __invoke(SymfonyStyle $io, #[Argument('Exact registered subscriber identity')] string $subscriber): int
    {
        $count = $this->notifier->flush($subscriber);
        $io->success(sprintf('%d completed translations queued for delivery.', $count));
        return 0;
    }
}
