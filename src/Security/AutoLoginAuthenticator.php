<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Single-user mode: authenticates every request as one fixed account, so the
 * app behaves as if permanently logged in with a single user. Removes any need
 * for the login form (which is bypassed entirely).
 *
 * The account is resolved through the configured user provider by its email
 * identifier — change SINGLE_USER_EMAIL to switch which account the whole app
 * runs as.
 */
class AutoLoginAuthenticator extends AbstractAuthenticator
{
    /** The one account the whole application is logged in as. */
    private const SINGLE_USER_EMAIL = 'musiclibrary@mail.com';

    public function supports(Request $request): ?bool
    {
        // Authenticate on every request handled by this firewall.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        return new SelfValidatingPassport(new UserBadge(self::SINGLE_USER_EMAIL));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Continue with the request; nothing to redirect.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Let the security layer turn this into the normal 401/redirect flow.
        return null;
    }
}
