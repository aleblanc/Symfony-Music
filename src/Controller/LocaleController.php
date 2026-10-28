<?php

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The sidebar language switcher: remembers the choice in the session, a
 * one-year cookie (anonymous visitors, after logout) and, when logged in,
 * the user's settings; then reloads the page the visitor was on.
 */
class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale_switch', methods: ['POST'], requirements: ['locale' => 'en|fr'])]
    public function switch(string $locale, Request $request, #[CurrentUser] ?User $user, EntityManagerInterface $em): RedirectResponse
    {
        $back = $request->headers->get('referer');
        if ($back === null || !str_starts_with($back, $request->getSchemeAndHttpHost() . '/')) {
            $back = $this->generateUrl('app_home');
        }
        $response = $this->redirect($back);
        if (!$this->isCsrfTokenValid('locale_switch', $request->request->getString('_token'))) {
            return $response;
        }

        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $locale);
        $response->headers->setCookie(Cookie::create(LocaleSubscriber::COOKIE, $locale, new \DateTimeImmutable('+1 year')));
        if ($user !== null) {
            $settings = json_decode($user->getSettings() ?? '', true);
            $settings = \is_array($settings) ? $settings : [];
            $settings['locale'] = $locale;
            $user->setSettings(json_encode($settings, \JSON_THROW_ON_ERROR));
            $em->flush();
        }

        return $response;
    }
}
