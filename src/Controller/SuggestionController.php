<?php

namespace App\Controller;

use App\Entity\MetadataSuggestion;
use App\Enum\SuggestionStatus;
use App\Repository\MetadataSuggestionRepository;
use App\Service\Enrichment\SuggestionApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Review queue for metadata proposed by `app:enrich` below the auto-apply
 * threshold: accept / reject one track or a whole album, revert applied ones.
 */
#[IsGranted('ROLE_ADMIN')]
class SuggestionController extends AbstractController
{
    #[Route('/admin/suggestions', name: 'app_suggestions', methods: ['GET'])]
    public function index(Request $request, MetadataSuggestionRepository $suggestions): Response
    {
        $status = SuggestionStatus::tryFrom((string) $request->query->get('status', 'pending')) ?? SuggestionStatus::Pending;

        // Group by release so an album reads (and can be accepted) together.
        $groups = [];
        foreach ($suggestions->findByStatus($status) as $suggestion) {
            $key = $suggestion->getReleaseId() ?? 'single-' . $suggestion->getId();
            $groups[$key][] = $suggestion;
        }

        return $this->render('suggestion/index.html.twig', [
            'status' => $status,
            'statuses' => SuggestionStatus::cases(),
            'counts' => $suggestions->countByStatus(),
            'groups' => $groups,
        ]);
    }

    #[Route('/admin/suggestions/{id}/cover', name: 'app_suggestion_cover', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cover(MetadataSuggestion $suggestion): Response
    {
        $bytes = $suggestion->getCover() ?? throw $this->createNotFoundException();

        return new Response($bytes, 200, [
            'Content-Type' => str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    #[Route('/admin/suggestions/{id}/accept', name: 'app_suggestion_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(MetadataSuggestion $suggestion, Request $request, SuggestionApplier $applier, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, 'suggestion_' . $suggestion->getId());

        if ($suggestion->isPending() && $applier->apply($suggestion)) {
            $this->addFlash('success', new TranslatableMessage('flash.suggestion.applied', ['title' => (string) $suggestion->getProposedValue('title')]));
        } else {
            // getError() is persisted text (stays as stored)
            $this->addFlash('error', $suggestion->getError() ?? new TranslatableMessage('flash.suggestion.cannot_apply'));
        }
        $em->flush();

        return $this->back($request);
    }

    #[Route('/admin/suggestions/{id}/reject', name: 'app_suggestion_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(MetadataSuggestion $suggestion, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, 'suggestion_' . $suggestion->getId());

        if ($suggestion->isPending()) {
            $suggestion->markRejected();
            $em->flush();
        }

        return $this->back($request);
    }

    #[Route('/admin/suggestions/{id}/revert', name: 'app_suggestion_revert', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revert(MetadataSuggestion $suggestion, Request $request, SuggestionApplier $applier, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, 'suggestion_' . $suggestion->getId());

        if ($applier->revert($suggestion)) {
            $em->flush();
            $this->addFlash('success', new TranslatableMessage('flash.suggestion.reverted'));
        } else {
            $this->addFlash('error', new TranslatableMessage('flash.suggestion.revert_failed'));
        }

        return $this->back($request);
    }

    #[Route('/admin/suggestions/release/{releaseId}/accept', name: 'app_suggestion_accept_release', methods: ['POST'], requirements: ['releaseId' => '[0-9a-f-]{36}'])]
    public function acceptRelease(string $releaseId, Request $request, MetadataSuggestionRepository $suggestions, SuggestionApplier $applier, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, 'suggestion_release_' . $releaseId);

        $applied = $failed = 0;
        foreach ($suggestions->findPendingForRelease($releaseId) as $suggestion) {
            $applier->apply($suggestion) ? ++$applied : ++$failed;
        }
        $em->flush();

        $this->addFlash($failed === 0 ? 'success' : 'error', new TranslatableMessage('flash.suggestion.release_applied', ['applied' => $applied, 'failed' => $failed]));

        return $this->back($request);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function back(Request $request): Response
    {
        return $this->redirectToRoute('app_suggestions', ['status' => $request->query->get('status', 'pending')]);
    }
}
