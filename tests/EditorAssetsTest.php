<?php

declare(strict_types=1);

namespace Neo\FrontendEditorBundle\Tests;

use Neo\FrontendEditorBundle\EditorAssets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Asset\VersionStrategy\StaticVersionStrategy;

final class EditorAssetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/nfe-assets-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/icons', 0777, true);
        file_put_contents($this->dir.'/editor.js', 'console.log(1);');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,icons/}*.*', GLOB_BRACE) ?: []);
        rmdir($this->dir.'/icons');
        rmdir($this->dir);
    }

    public function testAFileOfTheBundleGetsTheHashOfItsContent(): void
    {
        $url = $this->assets()->url('editor.js');

        $this->assertMatchesRegularExpression('#^/bundles/neofrontendeditor/editor\.js\?v=[0-9a-f]{12}$#', $url);
        $this->assertSame($url, $this->assets()->url('/editor.js'), 'the same content, the same address');
    }

    public function testAChangedFileGetsANewAddress(): void
    {
        $before = $this->assets()->url('editor.js');
        file_put_contents($this->dir.'/editor.js', 'console.log(2);');

        $this->assertNotSame($before, $this->assets()->url('editor.js'));
    }

    public function testAMissingFileKeepsItsPlainAddress(): void
    {
        $assets = $this->assets();

        $this->assertSame('/bundles/neofrontendeditor/icons/none.svg', $assets->url('icons/none.svg'));
        $this->assertFalse($assets->exists('icons/none.svg'));
        $this->assertTrue($assets->exists('editor.js'));
    }

    public function testTheVersionOfContaoStays(): void
    {
        // Contao 5.7 versions the files of a bundle by their modification time.
        $assets = new EditorAssets(new Packages(new PathPackage('/', new StaticVersionStrategy('1795037669', '%s?v=%s'))), $this->dir);

        $this->assertSame('/bundles/neofrontendeditor/editor.js?v=1795037669', $assets->url('editor.js'));
    }

    private function assets(): EditorAssets
    {
        return new EditorAssets(new Packages(new PathPackage('/', new EmptyVersionStrategy())), $this->dir);
    }
}
