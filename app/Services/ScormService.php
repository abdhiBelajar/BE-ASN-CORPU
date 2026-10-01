<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScormService
{
    /**
     * Ekstrak paket SCORM ZIP dan temukan entry point URL-nya.
     *
     * @param UploadedFile $zipFile
     * @return array ['url' => string, 'folder_name' => string]
     * @throws \Exception
     */
    public function extractAndGetEntryUrl(UploadedFile $zipFile): array
    {
        $folderName = 'scorm_' . date('Ymd_His') . '_' . Str::random(8);
        $relativeFolder = 'scorm/' . $folderName;
        $destPath = Storage::disk('public')->path($relativeFolder);

        if (!File::isDirectory($destPath)) {
            File::makeDirectory($destPath, 0755, true);
        }

        $tempZipPath = $zipFile->getRealPath();

        // 1. Ekstraksi arsip ZIP (Native ZipArchive atau Fallback OS)
        $extracted = false;
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($tempZipPath) === true) {
                // Zip Slip Protection: validasi nama file di dalam arsip
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    // Cegah path traversal
                    if (str_contains($entryName, '../') || str_contains($entryName, '..\\')) {
                        $zip->close();
                        $this->cleanupFolder($folderName);
                        throw new \Exception('Arsip ZIP tidak valid: terdeteksi path traversal.');
                    }
                }
                $zip->extractTo($destPath);
                $zip->close();
                $extracted = true;
            }
        }

        // Fallback jika ZipArchive tidak tersedia atau gagal
        if (!$extracted) {
            $escapedZip = escapeshellarg($tempZipPath);
            $escapedDest = escapeshellarg($destPath);
            // Coba tar terlebih dahulu (bawaan Windows 10/11 & Linux)
            @exec("tar -xf $escapedZip -C $escapedDest", $outputTar, $codeTar);
            if ($codeTar === 0) {
                $extracted = true;
            } else {
                // Fallback ke PowerShell Expand-Archive
                $psCmd = "powershell -NoProfile -Command \"Expand-Archive -LiteralPath $escapedZip -DestinationPath $escapedDest -Force\"";
                @exec($psCmd, $outputPs, $codePs);
                if ($codePs === 0) {
                    $extracted = true;
                }
            }
        }

        if (!$extracted) {
            $this->cleanupFolder($folderName);
            throw new \Exception('Gagal mengekstrak berkas ZIP SCORM.');
        }

        // 2. Keamanan: Hapus file skrip server berbahaya (.php, .phtml, .sh, .exe)
        $this->sanitizeExtractedFiles($destPath);

        // 3. Temukan entry point utama (imsmanifest.xml atau HTML launcher)
        $entryRelPath = $this->findLaunchFile($destPath);

        if (!$entryRelPath) {
            $this->cleanupFolder($folderName);
            throw new \Exception('Berkas SCORM tidak valid: Tidak ditemukan file peluncur (imsmanifest.xml atau index.html).');
        }

        $publicUrl = '/storage/' . $relativeFolder . '/' . ltrim($entryRelPath, '/\\');

        return [
            'url' => str_replace('\\', '/', $publicUrl),
            'folder_name' => $folderName,
        ];
    }

    /**
     * Cari file entry point SCORM
     */
    protected function findLaunchFile(string $dir): ?string
    {
        // 1. Cek imsmanifest.xml di root atau di subfolder level-1
        $manifestPath = $dir . DIRECTORY_SEPARATOR . 'imsmanifest.xml';
        $subPrefix = '';

        if (!File::exists($manifestPath)) {
            // Jika zip memiliki 1 folder pembungkus di dalamnya
            $subdirs = File::directories($dir);
            if (count($subdirs) === 1) {
                $candidate = $subdirs[0] . DIRECTORY_SEPARATOR . 'imsmanifest.xml';
                if (File::exists($candidate)) {
                    $manifestPath = $candidate;
                    $subPrefix = basename($subdirs[0]) . '/';
                }
            }
        }

        if (File::exists($manifestPath)) {
            $href = $this->parseManifestHref($manifestPath);
            if ($href) {
                return $subPrefix . $href;
            }
        }

        // 2. Fallback: Cari file HTML peluncur umum
        $commonLaunchers = [
            'index.html',
            'index_lms.html',
            'story.html',
            'story_html5.html',
            'launcher.html',
            'index.htm'
        ];

        // Cek di root
        foreach ($commonLaunchers as $launcher) {
            if (File::exists($dir . DIRECTORY_SEPARATOR . $launcher)) {
                return $launcher;
            }
        }

        // Cek di subfolder jika ada
        if (!empty($subdirs)) {
            foreach ($subdirs as $sdir) {
                $base = basename($sdir);
                foreach ($commonLaunchers as $launcher) {
                    if (File::exists($sdir . DIRECTORY_SEPARATOR . $launcher)) {
                        return $base . '/' . $launcher;
                    }
                }
            }
        }

        // Fallback rekursif: cari file .html pertama yang ditemukan
        $allHtmlFiles = File::glob($dir . '/*.html');
        if (!empty($allHtmlFiles)) {
            return basename($allHtmlFiles[0]);
        }

        return null;
    }

    /**
     * Baca tag <resource ... href="..."> dari imsmanifest.xml
     */
    protected function parseManifestHref(string $manifestFile): ?string
    {
        try {
            $xmlContent = file_get_contents($manifestFile);
            if (!$xmlContent) return null;

            // Hapus namespace agar parsing XML sederhana dan tidak rentan error XML schema
            $xmlContent = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlContent);
            $xml = @simplexml_load_string($xmlContent);
            if ($xml === false) return null;

            // Cari resource dengan tipe scorm
            if (isset($xml->resources) && isset($xml->resources->resource)) {
                foreach ($xml->resources->resource as $res) {
                    $href = (string)$res['href'];
                    if (!empty($href)) {
                        return str_replace('\\', '/', $href);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal parse imsmanifest.xml SCORM: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Hapus file berbahaya dari hasil ekstraksi
     */
    protected function sanitizeExtractedFiles(string $dir): void
    {
        try {
            $files = File::allFiles($dir);
            $forbiddenExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'cgi', 'pl', 'exe', 'bat', 'cmd', 'sh'];
            foreach ($files as $file) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $forbiddenExtensions)) {
                    @File::delete($file->getPathname());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal sanitasi SCORM: ' . $e->getMessage());
        }
    }

    /**
     * Hapus folder SCORM dari public storage jika tautannya bertipe lokal
     */
    public function deleteScormByUrl(?string $url): void
    {
        if (!$url || !str_contains($url, '/storage/scorm/')) {
            return;
        }

        try {
            // Contoh URL: /storage/scorm/scorm_20261001_1234_abcd/index.html
            if (preg_match('#/storage/scorm/([^/]+)#', $url, $matches)) {
                $folder = $matches[1];
                $this->cleanupFolder($folder);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal menghapus folder SCORM: ' . $e->getMessage());
        }
    }

    public function cleanupFolder(string $folderName): void
    {
        $dir = 'scorm/' . $folderName;
        if (Storage::disk('public')->exists($dir)) {
            Storage::disk('public')->deleteDirectory($dir);
        }
    }
}
