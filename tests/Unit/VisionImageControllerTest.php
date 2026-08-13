<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Foundation\Paths;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Laminas\Diactoros\ServerRequest;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Api\Controller\VisionImageController;

class VisionImageControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zai-bot-vision-' . uniqid();
        mkdir($this->tmpDir . '/assets/files/uploads', 0777, true);

        // 内存 SQLite 在进程内跨测试共享，清理上次测试留下的行，避免 UUID 冲突
        if (class_exists(\FoF\Upload\File::class)) {
            \FoF\Upload\File::query()->delete();
        }
    }

    protected function tearDown(): void
    {
        // 清理临时目录（含子目录）
        if (is_dir($this->tmpDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->tmpDir);
        }
    }

    protected function makeFile(array $overrides = []): \FoF\Upload\File
    {
        $file = new \FoF\Upload\File();
        $file->uuid = self::UUID;
        $file->base_name = 'photo.png';
        $file->path = 'uploads/photo.png';
        $file->type = 'image/png';
        $file->size = 10;
        $file->hidden = false;
        $file->url = '';

        foreach ($overrides as $key => $value) {
            $file->{$key} = $value;
        }

        $file->save();

        return $file;
    }

    protected function controller(
        ?FilesystemFactory $filesystem = null,
        ?Client $client = null
    ): VisionImageController {
        // Flarum 2.x 的 Paths 用私有数组存储路径，直接构造真实实例（public 指向临时目录）
        $paths = new Paths([
            'base' => $this->tmpDir,
            'public' => $this->tmpDir,
            'storage' => $this->tmpDir,
        ]);

        return new VisionImageController(
            $paths,
            $filesystem ?? Mockery::mock(FilesystemFactory::class),
            $client ?? Mockery::mock(Client::class)
        );
    }

    protected function request(string $uuid = self::UUID): ServerRequest
    {
        return (new ServerRequest())->withAttribute('uuid', $uuid);
    }

    public function testServesLocalFileBytes(): void
    {
        file_put_contents($this->tmpDir . '/assets/files/uploads/photo.png', 'FAKE_PNG_BYTES');
        $this->makeFile(['size' => strlen('FAKE_PNG_BYTES')]);

        $response = $this->controller()->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('FAKE_PNG_BYTES', (string) $response->getBody());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame((string) strlen('FAKE_PNG_BYTES'), $response->getHeaderLine('Content-Length'));
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $response = $this->controller()->handle($this->request('bbbbbbbb-cccc-dddd-eeee-ffffffffffff'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForHiddenFile(): void
    {
        $this->makeFile(['hidden' => true]);
        file_put_contents($this->tmpDir . '/assets/files/uploads/photo.png', 'FAKE_PNG_BYTES');

        $response = $this->controller()->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForNonImageType(): void
    {
        $this->makeFile(['type' => 'application/pdf']);
        file_put_contents($this->tmpDir . '/assets/files/uploads/photo.png', 'PDF_BYTES');

        $response = $this->controller()->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForOversizedFile(): void
    {
        // 超过 20MB 上限 → 拒绝代理
        $this->makeFile(['size' => 21 * 1024 * 1024]);

        $response = $this->controller()->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFallsBackToExternalUrl(): void
    {
        // 本地与 private-shared 都没有该文件时，从配置的外部 URL 拉取（如 S3）
        $file = $this->makeFile(['url' => 'https://storage.example/photo.png']);
        $this->assertNotNull($file);

        $filesystem = Mockery::mock(FilesystemFactory::class);
        $disk = Mockery::mock();
        $disk->shouldReceive('get')->once()->andThrow(new \Exception('not on private-shared disk'));
        $filesystem->shouldReceive('disk')->with('private-shared')->andReturn($disk);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with('https://storage.example/photo.png', Mockery::on(fn ($opts) => is_array($opts)))
            ->andReturn(new Response(200, [], 'S3_BYTES'));

        $response = $this->controller($filesystem, $client)->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('S3_BYTES', (string) $response->getBody());
    }

    public function testReturns404WhenNoSourceHasTheFile(): void
    {
        $file = $this->makeFile(['url' => 'https://storage.example/missing.png']);
        $this->assertNotNull($file);

        $filesystem = Mockery::mock(FilesystemFactory::class);
        $disk = Mockery::mock();
        $disk->shouldReceive('get')->once()->andThrow(new \Exception('missing'));
        $filesystem->shouldReceive('disk')->with('private-shared')->andReturn($disk);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('get')
            ->once()
            ->with('https://storage.example/missing.png', Mockery::on(fn ($opts) => is_array($opts)))
            ->andThrow(new \Exception('remote unreachable'));

        $response = $this->controller($filesystem, $client)->handle($this->request());

        $this->assertSame(404, $response->getStatusCode());
    }
}
