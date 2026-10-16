<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Project;
use App\Support\Reports\AgencyReportBuilder;
use App\Support\Reports\ProjectReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Generation des rapports PDF cote serveur (DOMPDF).
 *
 * Le calcul des donnees est delegue aux builders (App\Support\Reports) ;
 * le rendu HTML/CSS est fait par les vues Blade sous resources/views/reports.
 * Le fichier est renvoye en flux, jamais stocke : il reflete toujours l'etat
 * courant des donnees.
 */
class ReportController extends Controller
{
    // GET /api/projects/{project}/report
    public function project(Project $project)
    {
        $this->authorize('view', $project);

        $data = (new ProjectReportBuilder($project))->build();

        return Pdf::loadView('reports.report', $data)
            ->setPaper('a4')
            ->download($this->fileName('rapport', $project->name));
    }

    // GET /api/agencies/{agency}/report
    public function agency(Agency $agency)
    {
        $this->authorize('view', $agency);

        $data = (new AgencyReportBuilder($agency))->build();

        return Pdf::loadView('reports.report', $data)
            ->setPaper('a4')
            ->download($this->fileName('rapport-agence', $agency->name));
    }

    /** Nom de fichier sur (sans accents ni espaces). */
    private function fileName(string $prefix, string $value): string
    {
        $slug = Str::slug($value);

        return ($slug !== '' ? "{$prefix}-{$slug}" : $prefix).'.pdf';
    }
}
