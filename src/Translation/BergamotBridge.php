<?php

declare(strict_types=1);

namespace App\Translation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The native translation engine ai-tools uses (the EuroNano model on the Bergamot runtime), called from PHP through FFI.
 *
 * ai-tools reaches the engine through a small C bridge (ai-tools cv/native/bergamot_bridge.cpp: bn_load, bn_translate, ...)
 * that Python calls with ctypes. This class calls the same bridge, so a translation is a function call in this process:
 * no HTTP, no Python, no queue.
 *
 * It needs three files, all built or downloaded by ai-tools and found under BERGAMOT_HOME (default: ../ai-tools/var):
 *   native/libbergamot_bridge.dylib          the C bridge
 *   native/libeuronano-qvac-10.0.0.dylib     the runtime
 *   models/euronano-native/<variant>/xx-en/  model.intgemm.alphas.bin and vocab.spm
 * Where they are missing (production today: the bridge is only built for macOS arm64) available() is false and nothing
 * else here may be called. Under a web server PHP also needs ffi.enable=1 (see php.ini).
 */
final class BergamotBridge
{
    private const CDEF = <<<'C'
        const char* bn_error(void);
        void* bn_load(const char* library, const char* model, const char* vocab);
        char* bn_translate(void* handle, const char* input, double* seconds, int* tokens);
        int bn_workers(void* handle);
        void bn_string_free(char* text);
        void bn_free(void* handle);
        C;

    private ?\FFI $ffi = null;
    private ?\FFI\CData $handle = null;
    private int $loads = 0;
    private int $translations = 0;

    public function __construct(
        #[Autowire('%env(resolve:BERGAMOT_HOME)%')] private readonly string $home,
        #[Autowire('%env(BERGAMOT_VARIANT)%')] private readonly string $variant = 'base',
    ) {
    }

    public function __destruct()
    {
        if ($this->handle !== null && $this->ffi !== null) {
            $this->ffi->bn_free($this->handle);
        }
    }

    public function available(): bool
    {
        return $this->unavailableBecause() === null;
    }

    /** Why the engine cannot be used here, in words for the page; null when it can. */
    public function unavailableBecause(): ?string
    {
        if (!\extension_loaded('ffi')) {
            return 'PHP has no FFI extension';
        }
        if (\PHP_SAPI !== 'cli' && !\in_array(strtolower((string) \ini_get('ffi.enable')), ['1', 'true', 'on'], true)) {
            return 'ffi.enable is not on for this server (php.ini)';
        }
        foreach ([$this->bridgePath(), $this->runtimePath(), $this->modelDir() . '/model.intgemm.alphas.bin', $this->modelDir() . '/vocab.spm'] as $file) {
            if (!is_file($file)) {
                return 'missing ' . $file;
            }
        }

        return null;
    }

    /**
     * How many times this process has loaded the model, and how many sentences it has translated with it.
     *
     * The model is loaded on first use and then kept for as long as this service lives. This service is deliberately not
     * resettable (no ResetInterface): in a FrankenPHP worker or a messenger:consume process the container outlives the
     * request or message, so one load serves everything that follows. Under a classic one-boot-per-request server each
     * request pays the load (about 30 ms) once.
     *
     * @return array{loads: int, translations: int}
     */
    public function usage(): array
    {
        return ['loads' => $this->loads, 'translations' => $this->translations];
    }

    public function variant(): string
    {
        return $this->variant;
    }

    /**
     * One sentence in, one sentence out.
     *
     * @return array{text: string, tokens: int, seconds: float}
     */
    public function translate(string $sentence): array
    {
        $ffi = $this->ffi();
        $seconds = $ffi->new('double');
        $tokens = $ffi->new('int');
        if ($this->handle === null) {
            throw new \RuntimeException('Native model is not loaded');
        }
        $pointer = $ffi->bn_translate($this->handle, $sentence, \FFI::addr($seconds), \FFI::addr($tokens));
        if ($pointer === null) {
            throw new \RuntimeException('Native inference failed: ' . $ffi->bn_error());
        }
        ++$this->translations;
        try {
            $text = \FFI::string($pointer);
        } finally {
            $ffi->bn_string_free($pointer);
        }

        // The two numbers the engine wrote through the pointers, read back from their bytes.
        return [
            'text' => $text,
            'tokens' => (int) (unpack('l', \FFI::string(\FFI::addr($tokens), 4))[1] ?? 0),
            'seconds' => (float) (unpack('d', \FFI::string(\FFI::addr($seconds), 8))[1] ?? 0.0),
        ];
    }

    /** Loads the bridge and the model on first use: about 30 ms on an M4. */
    private function ffi(): \FFI
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }
        if (($why = $this->unavailableBecause()) !== null) {
            throw new \RuntimeException('Native translation is not available: ' . $why);
        }
        $ffi = \FFI::cdef(self::CDEF, $this->bridgePath());
        $handle = $ffi->bn_load($this->runtimePath(), $this->modelDir() . '/model.intgemm.alphas.bin', $this->modelDir() . '/vocab.spm');
        if ($handle === null) {
            throw new \RuntimeException('Native model initialization failed: ' . $ffi->bn_error());
        }
        $this->handle = $handle;
        ++$this->loads;

        return $this->ffi = $ffi;
    }

    private function bridgePath(): string
    {
        return $this->home . '/native/libbergamot_bridge.dylib';
    }

    private function runtimePath(): string
    {
        return $this->home . '/native/libeuronano-qvac-10.0.0.dylib';
    }

    private function modelDir(): string
    {
        return $this->home . '/models/euronano-native/' . $this->variant . '/xx-en';
    }
}
