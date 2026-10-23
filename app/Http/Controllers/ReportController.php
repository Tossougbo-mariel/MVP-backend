<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Project;
use App\Support\Reports\AgencyReportBuilder;
use App\Support\Reports\ProjectReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
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
    public function project(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $locale = $this->locale($request);
        App::setLocale($locale);

        $data = (new ProjectReportBuilder($project))->build();

        return Pdf::loadView('reports.report', $data)
            ->setPaper('a4')
            ->download($this->fileName($locale === 'en' ? 'project-report' : 'rapport-projet', $project->name));
    }

    // GET /api/agencies/{agency}/report
    public function agency(Request $request, Agency $agency)
    {
        $this->authorize('view', $agency);

        $locale = $this->locale($request);
        App::setLocale($locale);

        $data = (new AgencyReportBuilder($agency))->build();

        return Pdf::loadView('reports.report', $data)
            ->setPaper('a4')
            ->download($this->fileName($locale === 'en' ? 'agency-report' : 'rapport-agence', $agency->name));
    }

    /** Locale du rapport : `fr` par defaut, `en` uniquement si demande. */
    private function locale(Request $request): string
    {
        $locale = (string) $request->query('locale', '');

        return in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
    }

    /** Nom de fichier sur (sans accents ni espaces). */
    private function fileName(string $prefix, string $value): string
    {
        $slug = Str::slug($value);

        return ($slug !== '' ? "{$prefix}-{$slug}" : $prefix).'.pdf';
    }
}
