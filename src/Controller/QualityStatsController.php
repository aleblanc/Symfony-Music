<?php

namespace App\Controller;

use App\Enum\AudioQuality;
use App\Repository\MusicRepository;
use App\Service\QualityStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audio quality of the whole library: tracks per quality tier, per format and
 * per bitrate range, worst first, plus the list of the worst tracks (or of
 * one format's bitrate range) to know what to replace.
 */
#[IsGranted('ROLE_ADMIN')]
class QualityStatsController extends AbstractController
{
    private const LIST_LIMIT = 200;
    /** Default list: every lossy track up to this bitrate (below 193 kb/s: worth replacing). */
    private const LOW_MAX_KBPS = 192;
    private const LOW_LIST_LIMIT = 2000;

    #[Route('/admin/quality', name: 'app_quality_stats', methods: ['GET'])]
    public function index(Request $request, MusicRepository $music, QualityStats $stats): Response
    {
        $format = $request->query->get('format');
        $format = \is_string($format) && preg_match('/^[a-z0-9]{1,16}$/', $format) === 1 ? $format : null;
        $min = $request->query->has('min') ? max(0, $request->query->getInt('min')) : null;
        $max = $request->query->has('max') ? max(0, $request->query->getInt('max')) : null;

        $limit = self::LIST_LIMIT;
        if ($format !== null) {
            $tracks = $music->lossyTracksByBitrate($format, $min, $max, self::LIST_LIMIT);
            $listTitle = QualityStats::formatLabel($format) . match (true) {
                $min !== null && $max !== null => \sprintf(', %d–%d kb/s', $min, $max),
                $min !== null => \sprintf(', ≥ %d kb/s', $min),
                $max !== null => \sprintf(', ≤ %d kb/s', $max),
                default => '',
            };
        } else {
            $limit = self::LOW_LIST_LIMIT;
            $tracks = $music->lossyTracksByBitrate(null, null, self::LOW_MAX_KBPS, $limit);
            $listTitle = \sprintf('Tracks under %d kb/s', self::LOW_MAX_KBPS + 1);
        }

        return $this->render('quality/index.html.twig', [
            'stats' => $stats->build($music->qualityRows()),
            'tracks' => array_map(static fn(array $t) => $t + [
                'quality' => AudioQuality::classify($t['format'], $t['lossless'], $t['bitrate']),
                'formatLabel' => QualityStats::formatLabel($t['format']),
            ], $tracks),
            'listTitle' => $listTitle,
            'listLimit' => $limit,
            'filtered' => $format !== null,
        ]);
    }
}
