<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests;

use Neo\FrontendEditorBundle\EditMode;
use Neo\FrontendEditorBundle\Security\Permission;
use Neo\FrontendEditorBundle\Tests\Support\EditModes;
use Neo\FrontendEditorBundle\Tests\Support\Users;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EditModeTest extends TestCase
{
    use EditModes;

    public function testAPageIsEditedByAPreviewUserWhoMayAndSwitchedTheEditorOn(): void
    {
        $user = Users::editor(Permission::EditMode);

        $this->assertSame($user, $this->editMode($user)->editor($this->editedPage()));
    }

    public function testVisitorsNeverGetTheEditor(): void
    {
        $visitor = Request::create('https://www.example.com/about.html', 'GET', [], [EditMode::COOKIE => '1']);

        // Not the preview, even with the cookie and a back-end user logged in elsewhere.
        $this->assertNull($this->editMode(Users::admin())->editor($visitor));
        $this->assertNull($this->editMode(Users::admin())->editor($this->editedPage(preview: false)));
        $this->assertFalse($this->editMode(Users::admin())->mayEdit($visitor));
    }

    public function testThePreviewWithoutTheSwitchIsNotEdited(): void
    {
        $edit = $this->editMode(Users::admin());

        $this->assertNull($edit->editor($this->editedPage(cookie: false)));
        // The switch is shown, though.
        $this->assertTrue($edit->mayEdit($this->editedPage(cookie: false)));
    }

    public function testAUserWithoutTheSwitchOfTheEditModeDoesNotEdit(): void
    {
        $edit = $this->editMode(Users::editor(), switch: false);

        $this->assertNull($edit->editor($this->editedPage()));
        $this->assertFalse($edit->mayEdit($this->editedPage()));
    }

    public function testWithoutBackEndUserOrWithAnUnknownOneNobodyEdits(): void
    {
        $this->assertNull($this->editMode(null)->editor($this->editedPage()));
        $this->assertNull($this->editMode(null)->previewUser($this->editedPage()));
    }

    public function testTheMainRequestIsTheDefault(): void
    {
        $stack = new RequestStack();
        $this->assertNull($this->editMode(Users::admin(), stack: $stack)->editor());

        $stack->push($this->editedPage());
        $this->assertNotNull($this->editMode(Users::admin(), stack: $stack)->editor());
    }

    public function testThePageInTheFrameOfTheEditorView(): void
    {
        $edit = $this->editMode();

        $this->assertTrue($edit->isFrame(Request::create('/about.html?nfe_frame=1')));
        $framed = Request::create('/contact.html');
        $framed->headers->set('Sec-Fetch-Dest', 'iframe');
        $this->assertTrue($edit->isFrame($framed));
        $this->assertFalse($edit->isFrame(Request::create('/about.html')));
    }

    public function testADocumentOfItsOwnIsAPageTheBrowserNavigatesTo(): void
    {
        $edit = $this->editMode();
        $navigation = $this->editedPage('https://www.example.com/about.html');
        $navigation->headers->set('Sec-Fetch-Dest', 'document');
        $navigation->headers->set('Sec-Fetch-Mode', 'navigate');

        $this->assertTrue($edit->isDocumentRequest($navigation));
        // Without Fetch Metadata (older browsers) by the other conditions.
        $this->assertTrue($edit->isDocumentRequest($this->editedPage('https://www.example.com/about.html')));

        $fetch = $this->editedPage('https://www.example.com/about.html');
        $fetch->headers->set('Sec-Fetch-Mode', 'cors');
        $this->assertFalse($edit->isDocumentRequest($fetch));

        $xhr = $this->editedPage('https://www.example.com/about.html');
        $xhr->headers->set('X-Requested-With', 'XMLHttpRequest');
        $this->assertFalse($edit->isDocumentRequest($xhr));

        $this->assertFalse($edit->isDocumentRequest($this->editedPage()), 'the page in the frame');
        $this->assertFalse($edit->isDocumentRequest(Request::create('https://www.example.com/about.html', 'POST')));

        $json = $this->editedPage('https://www.example.com/about.html');
        $json->headers->set('Accept', 'application/json');
        $this->assertFalse($edit->isDocumentRequest($json));
    }
}
