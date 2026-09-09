<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ZipArchive;

class DevelopmentSetupController extends Controller
{
    public function index()
    {
        return view('development.setup');
    }

    public function download(Request $request)
    {
        abort_unless(class_exists(ZipArchive::class), 503, 'ZIP拡張が必要です。');
        $path = tempnam(sys_get_temp_dir(), 'rise-gate-dev-');
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 503);
        $root = base_path('tools/local-dev');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $content = file_get_contents($file->getPathname());
                // Windows PowerShell 5.1 needs a BOM for Japanese text in scripts.
                if (str_ends_with($relative, '.ps1')) {
                    $content = "\xEF\xBB\xBF".ltrim($content, "\xEF\xBB\xBF");
                }
                // cmd.exe expects Windows line endings, regardless of the Git checkout platform.
                if (str_ends_with($relative, '.cmd')) {
                    $content = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $content));
                }
                $zip->addFromString('RiseGateDev/'.$relative, $content);
            }
        }
        $url = parse_url(config('app.url'));
        $origin = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? 'os.rise-gate.com').(isset($url['port']) ? ':'.$url['port'] : '');
        $zip->addFromString('RiseGateDev/origins.json', json_encode(array_values(array_unique([$origin, 'https://os.rise-gate.com', 'http://localhost', 'http://127.0.0.1']))));
        $zip->close();

        return response()->download($path, 'RiseGateDev-Windows.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
