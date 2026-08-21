<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $users,
        Security $security,
        #[CurrentUser]
        ?User $current,
    ): Response {
        if ($current !== null) {
            return $this->redirectToRoute('app_home');
        }

        $error = null;
        $email = trim((string) $request->request->get('email', ''));
        $artist = trim((string) $request->request->get('artist', ''));

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');

            if (!$this->isCsrfTokenValid('register', (string) $request->request->get('_token'))) {
                $error = 'Invalid CSRF token, please retry.';
            } elseif ($email === '' || $artist === '' || $password === '') {
                $error = 'All fields are required.';
            } elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif (\strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif ($users->findOneBy(['email' => $email]) !== null) {
                $error = 'An account with this email already exists.';
            } elseif ($users->findOneBy(['artist' => $artist]) !== null) {
                $error = 'This artist name is already taken.';
            } else {
                $user = new User();
                $user->setEmail($email);
                $user->setArtist($artist);
                $user->setPasswordHash($passwordHasher->hashPassword($user, $password));
                $users->save($user);
                $security->login($user);

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('security/register.html.twig', [
            'error' => $error,
            'email' => $email,
            'artist' => $artist,
        ]);
    }
}
