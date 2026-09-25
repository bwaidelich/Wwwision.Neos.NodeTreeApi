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
     */
    public function translate(string $label): string
    {
        if (preg_match('/^([a-zA-Z0-9.]+):([a-zA-Z0-9.]+):([a-zA-Z0-9._-]+)$/', $label, $matches) !== 1) {
            return $label;
        }
        [, $packageKey, $sourceName, $id] = $matches;
        try {
            $translated = $this->translator->translateById($id, [], null, $this->locale(), str_replace('.', '/', $sourceName), $packageKey);
        } catch (\Exception) {
            return $label;
        }
        return $translated ?? $label;
    }
}
