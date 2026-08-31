<?php

namespace Zephyrisle\FlarumZaiBot\Service\Media;

use Flarum\Foundation\Paths;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Zephyrisle\FlarumZaiBot\Service\MediaExtractor;

/**
 * 文件解析：帖子/私信中引用的 fof/upload 文件自动注入文件名与大小；
 * 文本文件读取开头片段、PDF 读取开头文本，帮助 LLM 理解文件内容。
 *
 * 说明：
 *   - 仅解析本扩展可访问的文件（本地磁盘 public/assets/files 或 private-shared 磁盘）；
 *   - PDF 提取仅对未压缩的简单 PDF 有效（FlateDecode 压缩的正文无法直接读取），
 *     失败时仍注入文件名与大小；
 *   - 结果按文件 ID 缓存（30 天），相同文件直接复用。
 */
class FileParsingService
{
    private const CACHE_TTL_SECONDS = 30 * 86400;

    private const TEXT_PREVIEW_CHARS = 500;

    private const PDF_PREVIEW_CHARS = 800;

    public function __construct(
        protected Paths $paths,
        protected FilesystemFactory $filesystem,
        protected Repository $cache
    ) {
    }

    /**
     * 解析内容中引用的 fof/upload 文件，返回 [{name, size, type, preview}, ...]。
     */
    public function parse(string $contentHtml): array
    {
        if (!class_exists(\FoF\Upload\File::class)) {
            return [];
        }

        $results = [];
        foreach (MediaExtractor::uploadUuids($contentHtml) as $uuid) {
            $file = $this->findFile($uuid);
            if (!$file || $file->hidden) {
                continue;
            }
            $results[] = $this->describe($file);
        }

        return $results;
    }

    protected function findFile(string $uuid): ?\FoF\Upload\File
    {
        try {
            $hasUuidColumn = \FoF\Upload\File::query()
                ->getConnection()
                ->getSchemaBuilder()
                ->hasColumn('fof_upload_files', 'uuid');
        } catch (\Throwable $e) {
            $hasUuidColumn = false;
        }

        try {
            if ($hasUuidColumn) {
                return \FoF\Upload\File::where('uuid', $uuid)->first();
            }

            return \FoF\Upload\File::where('id', $uuid)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{name: string, size: string, type: string, preview: string}
     */
    protected function describe(\FoF\Upload\File $file): array
    {
        $key = 'zai-file:' . ($file->uuid ?? (string) $file->id);

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $name = $file->base_name ?: '未知文件';
        $size = $this->humanSize((int) $file->size);
        $type = (string) ($file->type ?: '');
        $preview = '';

        if (str_starts_with($type, 'text/')) {
            $bytes = $this->readFile($file);
            if ($bytes !== null) {
                $preview = trim(mb_substr($bytes, 0, self::TEXT_PREVIEW_CHARS));
            }
        } elseif ($type === 'application/pdf') {
            $bytes = $this->readFile($file);
            if ($bytes !== null) {
                $preview = $this->pdfTextPreview($bytes);
            }
        }

        $info = ['name' => $name, 'size' => $size, 'type' => $type, 'preview' => $preview];
        $this->cache->put($key, $info, self::CACHE_TTL_SECONDS);

        return $info;
    }

    protected function readFile(\FoF\Upload\File $file): ?string
    {
        // 本地磁盘（默认适配器）
        try {
            $localPath = $this->paths->public . '/assets/files/' . $file->path;
            if (is_file($localPath)) {
                $bytes = @file_get_contents($localPath);
                if ($bytes !== false && $bytes !== '') {
                    return $bytes;
                }
            }
        } catch (\Throwable $e) {
        }

        // private-shared 磁盘
        try {
            $bytes = $this->filesystem->disk('private-shared')->get($file->path);
            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * 简单 PDF 的文本提取：抓取未压缩文本对象中的括号字符串。
     * 返回空字符串表示无法提取（如 FlateDecode 压缩），调用方回退为仅文件名/大小。
     */
    protected function pdfTextPreview(string $bytes): string
    {
        $text = '';
        if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $bytes, $matches)) {
            foreach ($matches[0] as $token) {
                $text .= substr($token, 1, -1) . ' ';
                if (mb_strlen($text) >= self::PDF_PREVIEW_CHARS) {
                    break;
                }
            }
        }

        return trim(mb_substr($text, 0, self::PDF_PREVIEW_CHARS));
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 10 ? 0 : 1) . ' ' . $units[$i];
    }
}
