<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Subsonic\SubsonicException;
use App\Subsonic\SubsonicHandler;
use App\Subsonic\SubsonicRequest;
use App\Subsonic\SubsonicResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Subsonic / OpenSubsonic REST API entry point: one route, `/rest/{method}`
 * (with or without the legacy `.view` suffix), dispatched to the handler
 * that declares the method. Authentication happens in front of PHP (Apache
 * Basic Auth); the Subsonic u/t/s/p/apiKey parameters are accepted and
 * ignored, and the request runs as the single auto-logged-in user.
 *
 * Errors follow the protocol: HTTP 200 with status="failed".
 */
#[AsController]
final class SubsonicController
{
    /** @var array<string, SubsonicHandler> */
    private array $handlers = [];

    /**
     * @param iterable<SubsonicHandler> $handlers
     */
    public function __construct(
        #[AutowireIterator(SubsonicHandler::TAG)] iterable $handlers,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($handlers as $handler) {
            foreach ($handler::methods() as $method) {
                $this->handlers[strtolower($method)] = $handler;
            }
        }
    }

    #[Route('/rest/{endpoint}', name: 'api_subsonic', requirements: ['endpoint' => '[A-Za-z0-9]+(\.view)?'], methods: ['GET', 'POST'])]
    public function __invoke(string $endpoint, Request $request, #[CurrentUser] ?User $user): Response
    {
        $params = SubsonicRequest::fromRequest($request);
        $format = $params->format();
        $method = preg_replace('/\.view$/', '', $endpoint) ?? $endpoint;
        $handler = $this->handlers[strtolower($method)] ?? null;

        if ($handler === null) {
            return SubsonicResponse::error(SubsonicException::GENERIC, "Not implemented: {$method}", $format);
        }
        if ($user === null) {
            return SubsonicResponse::error(SubsonicException::NOT_AUTHORIZED, 'No user', $format);
        }

        try {
            $result = $handler->handle($method, $params, $user);
        } catch (SubsonicException $e) {
            return SubsonicResponse::error($e->getCode(), $e->getMessage(), $format);
        } catch (\Throwable $e) {
            $this->logger->error('Subsonic {method} failed: {error}', ['method' => $method, 'error' => $e->getMessage(), 'exception' => $e]);

            return SubsonicResponse::error(SubsonicException::GENERIC, 'Server error', $format);
        }

        return $result instanceof Response ? $result : SubsonicResponse::ok($result, $format);
    }
}
