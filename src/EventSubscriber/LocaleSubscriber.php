<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The UI language of each request, no locale in the URL: the one picked with
 * the language switcher (session, then the long-lived cookie for anonymous
 * visitors), else the browser's Accept-Language, else English.
 * Runs before the router's LocaleListener (16). At login, the language saved
 * in the user's settings is copied into the session.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const string SESSION_KEY = '_locale';
    public const string COOKIE = 'locale';

    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $locale = $request->hasPreviousSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $locale ??= $request->cookies->get(self::COOKIE);
        if (!\in_array($locale, $this->enabledLocales, true)) {
            $locale = $request->getPreferredLanguage($this->enabledLocales) ?? $this->defaultLocale;
        }
        $request->setLocale($locale);
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $settings = $user instanceof User ? json_decode($user->getSettings() ?? '', true) : null;
        $locale = \is_array($settings) ? ($settings['locale'] ?? null) : null;
        if (\in_array($locale, $this->enabledLocales, true)) {
            $event->getRequest()->getSession()->set(self::SESSION_KEY, $locale);
        }
    }
}
