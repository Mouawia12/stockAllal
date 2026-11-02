<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Telechargement de l'agent a copier sur le serveur Gescomm d'une branche, et de son guide PDF. */
class AgentDownloadController extends Controller
{
    /**
     * Ce qu'il faut sur la branche ; le reste du dossier agent/ (sources de l'installateur, tests, source du guide) n'y sert pas.
     * GescomAgent-Setup.exe installe l'agent.ps1 du meme zip : une nouvelle version de l'agent part sans recompiler l'exe.
     */
    private const FILES = [
        'GescomAgent-Setup.exe', 'agent.ps1', 'install.ps1', 'uninstall.ps1', 'diagnose.ps1', 'README.md', 'GescomAgent-Guide.pdf',
    ];

    public function zip(): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'extension PHP zip absente sur le serveur');

        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/agent-'.Str::random(12).'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (self::FILES as $file) {
            if (is_file(base_path('agent/'.$file))) {
                $zip->addFile(base_path('agent/'.$file), 'GescomAgent/'.$file);
            }
        }
        $zip->close();

        return response()->download($path, 'GescomAgent-'.self::version().'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    public function guide(): BinaryFileResponse
    {
        return response()->file(base_path('agent/GescomAgent-Guide.pdf'), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="GescomAgent-Guide.pdf"',
        ]);
    }

    /** Version lue dans agent.ps1, pour nommer le zip et l'afficher dans la page des branches. */
    public static function version(): string
    {
        $source = @file_get_contents(base_path('agent/agent.ps1')) ?: '';

        return preg_match("/AgentVersion = '([^']+)'/", $source, $m) ? $m[1] : '?';
    }
}
