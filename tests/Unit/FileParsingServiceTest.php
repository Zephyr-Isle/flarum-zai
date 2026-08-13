<?php

namespace Zephyrisle\FlarumZaiBot\Tests\Unit;

use Flarum\Foundation\Paths;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Zephyrisle\FlarumZaiBot\Service\Media\FileParsingService;

class FileParsingServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zai-bot-files-' . uniqid();
        mkdir($this->tmpDir . '/assets/files/uploads', 0777, true);

        if (class_exists(\FoF\Upload\File::class)) {
            \FoF\Upload\File::query()->delete();
        }
    }

    protected function tearDown(): void
    {
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

    protected function service(): FileParsingService
    {
        return new FileParsingService(
            new Paths(['base' => $this->tmpDir, 'public' => $this->tmpDir, 'storage' => $this->tmpDir]),
            Mockery::mock(FilesystemFactory::class),
            new CacheRepository(new ArrayStore())
        );
    }

    protected function makeFile(array $overrides = []): \FoF\Upload\File
    {
        $file = new \FoF\Upload\File();
        $file->uuid = self::UUID;
        $file->base_name = 'notes.txt';
        $file->path = 'uploads/notes.txt';
        $file->type = 'text/plain';
        $file->size = 10;
        $file->hidden = false;
        $file->url = '';

        foreach ($overrides as $key => $value) {
            $file->{$key} = $value;
        }

        $file->save();

        return $file;
    }

    protected function uploadHtml(string $uuid = self::UUID): string
    {
        return "看看这个文件 <a href=\"https://forum.example/api/fof/download/{$uuid}\">下载</a>";
    }

    public function testDescribesTextFileWithPreview(): void
    {
        file_put_contents($this->tmpDir . '/assets/files/uploads/notes.txt', 'hello world content here');
        $this->makeFile(['size' => strlen('hello world content here')]);

        $results = $this->service()->parse($this->uploadHtml());

        $this->assertCount(1, $results);
        $this->assertSame('notes.txt', $results[0]['name']);
        $this->assertSame((string) strlen('hello world content here') . ' B', $results[0]['size']);
        $this->assertSame('hello world content here', $results[0]['preview']);
    }

    public function testPdfBestEffortTextExtraction(): void
    {
        file_put_contents($this->tmpDir . '/assets/files/uploads/notes.txt', '(Hello PDF world) Tj');
        $this->makeFile(['type' => 'application/pdf', 'size' => 18]);

        $results = $this->service()->parse($this->uploadHtml());

        $this->assertCount(1, $results);
        $this->assertSame('Hello PDF world', $results[0]['preview']);
    }

    public function testBinaryTypeHasNoPreview(): void
    {
        file_put_contents($this->tmpDir . '/assets/files/uploads/notes.txt', "\x00\x01binary\x02");
        $this->makeFile(['base_name' => 'file.bin', 'type' => 'application/octet-stream', 'size' => 8]);

        $results = $this->service()->parse($this->uploadHtml());

        $this->assertCount(1, $results);
        $this->assertSame('file.bin', $results[0]['name']);
        $this->assertSame('', $results[0]['preview']);
    }

    public function testSkipsHiddenFile(): void
    {
        $this->makeFile(['hidden' => true]);

        $this->assertSame([], $this->service()->parse($this->uploadHtml()));
    }

    public function testResultIsCached(): void
    {
        file_put_contents($this->tmpDir . '/assets/files/uploads/notes.txt', 'cached content');
        $this->makeFile(['size' => strlen('cached content')]);

        $service = $this->service();

        $first = $service->parse($this->uploadHtml());
        $second = $service->parse($this->uploadHtml());

        $this->assertSame($first, $second);
    }
}
