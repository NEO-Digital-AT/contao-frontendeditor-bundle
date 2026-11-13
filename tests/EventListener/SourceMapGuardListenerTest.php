<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests\EventListener;

use Neo\FrontendEditorBundle\Content\SourceMap\Markers;
use Neo\FrontendEditorBundle\EventListener\SourceMapGuardListener;
use Neo\FrontendEditorBundle\Tests\Support\EditModes;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SourceMapGuardListenerTest extends TestCase
{
    use EditModes;

    public function testNoMarkerLeavesTheServerAndTheEditedPageIsNotCached(): void
    {
        $response = new Response('<p title="'.Markers::wrap('Hello', 1).'">'.Markers::wrap('Hello', 1).'</p>');
        $response->setPublic();
        $response->setMaxAge(600);

        (new SourceMapGuardListener($this->editMode(Users::admin())))($this->event($this->editedPage(), $response));

        $this->assertSame('<p title="Hello">Hello</p>', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testResponsesForVisitorsAreNotTouched(): void
    {
        $html = '<!DOCTYPE html><html><body><p>Hello</p></body></html>';
        $response = new Response($html);
        $response->setPublic();
        $response->setMaxAge(600);
        $before = clone $response->headers;

        (new SourceMapGuardListener($this->editMode(Users::admin())))($this->event(Request::create('https://www.example.com/'), $response));

        $this->assertSame($html, $response->getContent());
        $this->assertEquals($before->all(), $response->headers->all());
    }

    public function testSubRequestsAreLeftToTheMainRequest(): void
    {
        $response = new Response(Markers::wrap('Hello', 1));

        (new SourceMapGuardListener($this->editMode(Users::admin())))($this->event($this->editedPage(), $response, HttpKernelInterface::SUB_REQUEST));

        $this->assertTrue(Markers::contains((string) $response->getContent()));
    }

    private function event(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response);
    }
}
