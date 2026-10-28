<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The raw (ICU) messages the front-end scripts need, i.e. every `js.*` key of
 * the current locale with the English fallback filled in, rendered as the
 * #i18n-config JSON read by public/i18n.js.
 */
class I18nExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {}

    /** @return array<string, string> */
    #[AsTwigFunction('js_translations')]
    public function jsTranslations(string $prefix = 'js.'): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }
        $messages = [];
        $catalogue = $this->translator->getCatalogue($this->requestStack->getCurrentRequest()?->getLocale());
        for (; $catalogue !== null; $catalogue = $catalogue->getFallbackCatalogue()) {
            foreach ($catalogue->all('messages') as $key => $message) {
                if (str_starts_with($key, $prefix)) {
                    $messages[$key] ??= $message;
                }
            }
        }
        ksort($messages);

        return $messages;
    }
}
