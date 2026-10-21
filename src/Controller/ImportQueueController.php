<?php

namespace App\Controller;

use App\Entity\ImportItem;
use App\Repository\ImportItemRepository;
use App\Service\Enrichment\CoverArtClient;
use App\Service\Import\AudioFileReader;
use App\Service\Import\FileImporter;
use App\Service\Import\ImportResult;
use App\Service\Import\LibraryPath;
use App\Service\MetadataWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The files `app:import` was not sure about, one at a time: listen, pick a
 * candidate in one click (or 1-5 on the keyboard), and the file is tagged
 * and filed exactly like the command does.
 */
#[IsGranted('ROLE_ADMIN')]
class ImportQueueController extends AbstractController
{
    public function __construct(
        private readonly ImportItemRepository $items,
        private readonly string $importDir,
    ) {}

    #[Route('/admin/import', name: 'app_import_queue', methods: ['GET'])]
    public function queue(Request $request, AudioFileReader $reader, LibraryPath $libraryPath, MetadataWriter $writer): Response
    {
        $id = $request->query->getInt('id');
        $item = $id > 0 ? $this->items->find($id) : $this->items->findNextPending();
        if ($item !== null && $item->getStatus() !== ImportItem::PENDING) {
            $item = $this->items->findNextPending();
        }

        $tags = null;
        $destinations = [];
        $candidates = [];
        if ($item !== null) {
            $exists = is_file($item->getPath());
            $tags = $exists ? $reader->read($item->getPath()) : null;
            $candidates = $item->getCandidates();
            $ext = ($exists ? $writer->detectFormat($item->getPath()) : null) ?? pathinfo($item->getPath(), \PATHINFO_EXTENSION);
            foreach ($candidates as $i => $candidate) {
                $destinations[$i] = $libraryPath->relativePath($candidate->fields, $ext);
            }
        }

        return $this->render('import/queue.html.twig', [
            'item' => $item,
            'folder' => $item !== null ? ltrim(substr(\dirname($item->getPath()), \strlen(rtrim($this->importDir, '/'))), '/') : null,
            'tags' => $tags,
            'candidates' => $candidates,
            'destinations' => $destinations,
            'next' => $item !== null ? $this->items->findNextPending($item) : null,
            'counts' => $this->items->countByStatus(),
        ]);
    }

    #[Route('/admin/import/{id}/audio', name: 'app_import_audio', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function audio(ImportItem $item): Response
    {
        $path = $this->insideImportDir($item) ?? throw $this->createNotFoundException();
        $response = new BinaryFileResponse($path);
        // Set explicitly: guessing it needs symfony/mime, not a dependency here.
        $response->headers->set('Content-Type', match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a', 'mp4', 'm4b' => 'audio/mp4',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        });
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    #[Route('/admin/import/{id}/choose/{index}', name: 'app_import_choose', methods: ['POST'], requirements: ['id' => '\d+', 'index' => '\d+'])]
    public function choose(ImportItem $item, int $index, Request $request, FileImporter $filer, CoverArtClient $coverArt, AudioFileReader $reader, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, $item);
        $candidate = $item->getCandidates()[$index] ?? null;
        $path = $this->insideImportDir($item);
        if ($item->getStatus() !== ImportItem::PENDING || $candidate === null || $path === null) {
            $this->addFlash('error', 'This file or candidate is no longer available.');

            return $this->redirectToRoute('app_import_queue');
        }

        // Candidates are stored without cover bytes: fetch the chosen one's
        // when the file has no artwork of its own.
        $cover = null;
        if ($candidate->releaseId !== null && !$reader->read($path)['hasImage']) {
            $cover = $coverArt->front($candidate->releaseId, $candidate->releaseGroupId);
        }

        try {
            $result = $filer->import($path, $this->importDir, $candidate, $cover);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $item->markChoiceFailed($e->getMessage());
            $em->flush();
            $this->addFlash('error', \sprintf('Not filed: %s', $e->getMessage()));

            return $this->redirectToRoute('app_import_queue', ['id' => $item->getId()]);
        }

        if ($result->status === ImportResult::DUPLICATE) {
            $item->markDuplicate($result->destination);
            $this->addFlash('success', \sprintf('%s was already in the library: moved to the duplicates folder.', $item->getOriginalName()));
        } else {
            $item->markImported($result->destination);
            $this->addFlash('success', \sprintf('Filed: %s', basename($result->destination)));
        }
        $em->flush();

        return $this->redirectToNext($item);
    }

    #[Route('/admin/import/{id}/skip', name: 'app_import_skip', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function skip(ImportItem $item, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, $item);
        if ($item->getStatus() === ImportItem::PENDING) {
            $item->markSkipped();
            $em->flush();
        }

        return $this->redirectToNext($item);
    }

    /** None of the candidates: kept as is under MUSIC_DIR/_unclassified, like app:import does for files nothing identifies. */
    #[Route('/admin/import/{id}/unclassified', name: 'app_import_unclassified', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unclassified(ImportItem $item, Request $request, FileImporter $filer, EntityManagerInterface $em): Response
    {
        $this->assertToken($request, $item);
        $path = $this->insideImportDir($item);
        if ($item->getStatus() !== ImportItem::PENDING || $path === null) {
            $this->addFlash('error', 'This file is no longer available.');

            return $this->redirectToRoute('app_import_queue');
        }

        try {
            $result = $filer->fileUnclassified($path, $this->importDir);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $item->markChoiceFailed($e->getMessage());
            $em->flush();
            $this->addFlash('error', \sprintf('Not filed: %s', $e->getMessage()));

            return $this->redirectToRoute('app_import_queue', ['id' => $item->getId()]);
        }

        if ($result->status === ImportResult::DUPLICATE) {
            $item->markDuplicate($result->destination);
            $this->addFlash('success', \sprintf('%s was already in %s: moved to the duplicates folder.', $item->getOriginalName(), FileImporter::UNCLASSIFIED_DIR));
        } else {
            $item->markImported($result->destination);
            $this->addFlash('success', \sprintf('Kept as is: %s/%s', FileImporter::UNCLASSIFIED_DIR, basename($result->destination)));
        }
        $em->flush();

        return $this->redirectToNext($item);
    }

    private function redirectToNext(ImportItem $item): Response
    {
        $next = $this->items->findNextPending($item);

        return $this->redirectToRoute('app_import_queue', $next !== null ? ['id' => $next->getId()] : []);
    }

    /** The item's file, only if it really lies inside the import folder. */
    private function insideImportDir(ImportItem $item): ?string
    {
        $real = realpath($item->getPath());
        $root = realpath($this->importDir);

        return $real !== false && $root !== false && str_starts_with($real, $root . '/') && is_file($real) ? $real : null;
    }

    private function assertToken(Request $request, ImportItem $item): void
    {
        if (!$this->isCsrfTokenValid('import_' . $item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
