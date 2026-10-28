<?php

namespace App\Controller;

use App\Service\DuplicateFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Exception\UserFacingException;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Tracks the library holds twice, to pick the one to keep: the others'
 * hearts, plays and playlist entries move to it, their files go to
 * MUSIC_DIR/_duplicates.
 */
#[IsGranted('ROLE_ADMIN')]
class DuplicatesController extends AbstractController
{
    #[Route('/admin/duplicates', name: 'app_duplicates', methods: ['GET'])]
    public function index(DuplicateFinder $finder): Response
    {
        return $this->render('duplicates/index.html.twig', [
            'groups' => $finder->groups(),
            'trashDir' => DuplicateFinder::TRASH_DIR,
        ]);
    }

    #[Route('/admin/duplicates/keep/{id}', name: 'app_duplicates_keep', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function keep(int $id, Request $request, DuplicateFinder $finder): Response
    {
        if (!$this->isCsrfTokenValid('duplicates_keep_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $drop = array_map('intval', $request->request->all('drop'));

        try {
            $moved = $finder->merge($id, $drop);
            $this->addFlash('success', new TranslatableMessage('flash.duplicates.removed', ['count' => \count($drop), 'moved' => \count($moved), 'dirs' => implode(', ', $moved)]));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', UserFacingException::messageFor($e));
        }

        return $this->redirect($this->generateUrl('app_duplicates') . (\is_string($next = $request->request->get('next')) && preg_match('/^g\d+$/', $next) === 1 ? '#' . $next : ''));
    }
}
