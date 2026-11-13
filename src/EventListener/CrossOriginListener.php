<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\EventListener;

use Neo\FrontendEditorBundle\SiteOrigins;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * When the back end runs under its own address (e.g. manage.example.com), the
 * editor on the website (example.com) talks to it across addresses. Only the
 * addresses of the website's root pages (SiteOrigins) get that:
 *
 * - the editor's endpoints answer them with CORS headers (with credentials),
 *   including the preflight request;
 * - back-end pages opened in a frame (Contao's form, the picker) may be
 *   embedded by them (frame-ancestors) instead of only by the back end itself.
 *
 * With back end and website under the same address nothing changes.
 */
final class CrossOriginListener
{
    public function __construct(
        private readonly SiteOrigins $origins,
        #[Autowire('%contao.backend.route_prefix%')]
        private readonly string $backendPrefix,
    ) {
    }

    /** Answers the preflight request before routing and firewall (it carries no credentials). */
    #[AsEventListener(KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || 'OPTIONS' !== $request->getMethod() || !$this->isEditorEndpoint($request)) {
            return;
        }

        $origin = $this->foreignOrigin($request);

        if (null === $origin) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->addCorsHeaders($response, $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Request-Token');
        $response->headers->set('Access-Control-Max-Age', '600');
        $event->setResponse($response);
    }

    /** Runs after Contao's and the framework's own security headers. */
    #[AsEventListener(KernelEvents::RESPONSE, priority: -1024)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($this->isEditorEndpoint($request) && null !== ($origin = $this->foreignOrigin($request))) {
            $this->addCorsHeaders($response, $origin);
        }

        // Framed back-end pages: Contao's popup pages (the form, the pickers) and the
        // editor's own; Sec-Fetch-Dest only arrives over HTTPS.
        $framed = 'iframe' === $request->headers->get('Sec-Fetch-Dest') || $request->query->has('popup') || $this->isEditorEndpoint($request);

        if ($framed && $this->isBackendPath($request)) {
            $this->allowFraming($request, $response);
        }
    }

    private function addCorsHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->setVary(array_unique([...$response->getVary(), 'Origin']));
    }

    private function allowFraming(Request $request, Response $response): void
    {
        $own = strtolower($request->getSchemeAndHttpHost());
        $others = array_values(array_filter($this->origins->all(), static fn (string $origin): bool => $origin !== $own));

        if ([] === $others) {
            return;
        }

        $ancestors = "frame-ancestors 'self' ".implode(' ', $others);
        $policies = $response->headers->all('content-security-policy');
        $found = false;

        // Contao may send several policies (e.g. one only with frame-ancestors for popups).
        foreach ($policies as $index => $policy) {
            if (str_contains($policy, 'frame-ancestors')) {
                $policies[$index] = (string) preg_replace('/frame-ancestors[^;]*/', $ancestors, $policy);
                $found = true;
            }
        }

        if (!$found) {
            $policies[] = $ancestors;
        }

        $response->headers->set('Content-Security-Policy', array_values($policies));
        $response->headers->remove('X-Frame-Options');
    }

    /** The Origin header when it is an address of the website other than this one. */
    private function foreignOrigin(Request $request): string|null
    {
        $origin = strtolower(rtrim((string) $request->headers->get('Origin', ''), '/'));

        if ('' === $origin || $origin === strtolower($request->getSchemeAndHttpHost()) || !$this->origins->allows($origin)) {
            return null;
        }

        return $origin;
    }

    private function isEditorEndpoint(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/'.trim($this->backendPrefix, '/').'/frontend-editor/');
    }

    private function isBackendPath(Request $request): bool
    {
        $prefix = '/'.trim($this->backendPrefix, '/');

        return $request->getPathInfo() === $prefix || str_starts_with($request->getPathInfo(), $prefix.'/');
    }
}
