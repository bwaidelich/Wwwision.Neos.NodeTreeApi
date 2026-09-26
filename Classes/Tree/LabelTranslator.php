<?php

declare(strict_types=1);

namespace Wwwision\Neos\NodeTreeApi\Tree;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Service\UserService;

/**
 * Translates labels into the interface language of the current backend user
 */
#[Flow\Scope('singleton')]
final readonly class LabelTranslator
{
    private const string PACKAGE_KEY = 'Wwwision.Neos.NodeTreeApi';

    public function __construct(
        private Translator $translator,
        private UserService $userService,
    ) {
    }

    public function locale(): Locale
    {
        return new Locale($this->userService->getInterfaceLanguage());
    }

    /**
     * Translates a shorthand string like "Neos.Neos:Main:document" – anything else is returned as it is
     *
     * @param array<mixed> $arguments
     */
    public function translate(string $label, array $arguments = []): string
    {
        if (preg_match('/^([a-zA-Z0-9.]+):([a-zA-Z0-9.]+):([a-zA-Z0-9._-]+)$/', $label, $matches) !== 1) {
            return $label;
        }
        [, $packageKey, $sourceName, $id] = $matches;
        return $this->translateById($id, $arguments, str_replace('.', '/', $sourceName), $packageKey) ?? $label;
    }

    /**
     * Translates a label of this package (see Resources/Private/Translations/<language>/Main.xlf)
     *
     * @param array<mixed> $arguments
     */
    public function translateOwn(string $id, array $arguments = []): string
    {
        return $this->translateById($id, $arguments, 'Main', self::PACKAGE_KEY) ?? $id;
    }

    /**
     * @param array<mixed> $arguments
     */
    private function translateById(string $id, array $arguments, string $sourceName, string $packageKey): string|null
    {
        try {
            return $this->translator->translateById($id, $arguments, null, $this->locale(), $sourceName, $packageKey);
        } catch (\Exception) {
            return null;
        }
    }
}
