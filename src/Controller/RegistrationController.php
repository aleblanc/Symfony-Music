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
use Symfony\Component\Translation\TranslatableMessage;

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
                $error = new TranslatableMessage('error.csrf_invalid_retry');
            } elseif ($email === '' || $artist === '' || $password === '') {
                $error = new TranslatableMessage('validator.registration.all_required');
            } elseif (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $error = new TranslatableMessage('validator.email.invalid');
            } elseif (\strlen($password) < 6) {
                $error = new TranslatableMessage('validator.password.too_short', ['min' => 6]);
            } elseif ($users->findOneBy(['email' => $email]) !== null) {
                $error = new TranslatableMessage('validator.email.taken');
            } elseif ($users->findOneBy(['artist' => $artist]) !== null) {
                $error = new TranslatableMessage('validator.artist.taken');
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
