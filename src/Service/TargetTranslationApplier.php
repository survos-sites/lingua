<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Target;
use App\Workflow\TargetWorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * THE single place a translation result is written onto a Target.
 *
 * Two callers, deliberately:
 *
 *   TargetWorkflow::onTransition            one Target, one HTTP call. Still the path for
 *                                           every TransitionMessage already sitting in the
 *                                           queue, and for anything dispatched per-target.
 *   TranslateBatchMessageHandler            N Targets sharing a (source, target, engine)
 *                                           tuple, one HTTP call for all of them.
 *
 * The translated-vs-identical decision is the reason this class exists rather than the
 * decision being copy-pasted. `identical` is not a failure — it means the engine returned the
 * source unchanged, which is the normal outcome for proper nouns — and a batch path that got
 * that test subtly different would mark thousands of rows wrong before anyone noticed.
 *
 * Deliberately does NOT flush. The single path flushes per Target because a workflow
 * transition must commit; the batch path flushes once for the whole page. Leaving that to the
 * caller is what makes the batch path worth having.
 */
final class TargetTranslationApplier
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string      $rawTranslation the engine's output, untrimmed
     * @param string|null $pivotLocale    locale the input text came from, when it was NOT the
     *                                    source text — i.e. the English-hub route. Recorded as
     *                                    provenance so a pivoted row is distinguishable from a
     *                                    direct one; see Target::$pivotLocale.
     *
     * @return bool whether anything changed (false = empty result, left untouched)
     */
    public function apply(Target $target, string $rawTranslation, ?string $pivotLocale = null): bool
    {
        $translation = trim($rawTranslation);

        if ($translation === '') {
            // Leave the row untranslated rather than storing '' — an empty string would look
            // like a completed translation to every consumer, including the webhook, and the
            // row would never be retried.
            $this->logger->warning('empty translation for {key} [{from}->{to}]', [
                'key' => $target->key,
                'from' => $target->source?->locale,
                'to' => $target->targetLocale,
            ]);

            return false;
        }

        $sourceText = $target->source?->getText() ?? '';

        $target->targetText = $translation;
        $target->pivotLocale = $pivotLocale;

        // Compared against the ORIGINAL source text even on a pivoted row. "identical" means
        // "the client gets back what it sent" — that is what a consumer acts on. Comparing
        // against the English intermediate instead would flag a Danish→Hungarian row as
        // identical whenever the English and Hungarian happened to match, which is not the
        // question anyone is asking.
        $target->setMarking($translation === $sourceText
            ? TargetWorkflowInterface::PLACE_IDENTICAL
            : TargetWorkflowInterface::PLACE_TRANSLATED);

        $this->logger->info(sprintf(
            '%s [%s->%s] %s -> %s',
            $target->getMarking(),
            $target->source?->locale,
            $target->targetLocale,
            self::snippet($sourceText),
            self::snippet($translation),
        ));

        return true;
    }

    /**
     * Trim long text for log lines, appending the real char count when trimmed — a long
     * source/target string is exactly what explains a slow translation call, so the count
     * needs to survive the trim rather than disappear with the cut text.
     */
    public static function snippet(string $text, int $max = 60): string
    {
        $len = mb_strlen($text);
        if ($len <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . "… ({$len} chars)";
    }
}
