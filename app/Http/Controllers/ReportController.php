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

        return $this->pdf($data)
            ->download($this->fileName('rapport', $project->name));
    }

    // GET /api/agencies/{agency}/report
    public function agency(Agency $agency)
    {
        $this->authorize('view', $agency);

        $data = (new AgencyReportBuilder($agency))->build();

        return $this->pdf($data)
            ->download($this->fileName('rapport-agence', $agency->name));
    }

    /**
     * Rendu du PDF commun (vue partagee) avec pied de page canvas :
     * libelle a gauche, pagination a droite. dompdf ne remplace pas
     * {PAGE_NUM}/{PAGE_COUNT} dans le corps du document, d'ou ce trace
     * cote controleur via page_script (repete sur chaque page).
     */
    private function pdf(array $data)
    {
        $label = ! empty($data['subtitle'])
            ? "{$data['title']} : {$data['subtitle']}"
            : $data['title'];

        $pdf = Pdf::loadView('reports.report', $data)->setPaper('a4');

        // Mise en page complete avant d'enregistrer le pied de page :
        // page_script s'execute immediatement, il faut que toutes les
        // pages existent deja pour que la pagination soit juste.
        $pdf->render();

        // Coordonnees A4 (595.28 x 841.89), origine haut-gauche comme le
        // reste du canevas dompdf. Alignes sur les marges de la vue (22 mm).
        $pdf->getDomPDF()->getCanvas()->page_script(
            function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($label) {
                $font = $fontMetrics->getFont('helvetica');
                $size = 8.25;
                $color = [0.42, 0.447, 0.502]; // #6b7280
                $left = 62.4;
                $right = 595.28 - 62.4;
                $y = 799.2;

                $canvas->text($left, $y, $label, $font, $size, $color);

                $page = "Page {$pageNumber} / {$pageCount}";
                $width = $fontMetrics->getTextWidth($page, $font, $size);
                $canvas->text($right - $width, $y, $page, $font, $size, $color);
            }
        );

        return $pdf;
    }

    /** Nom de fichier sur (sans accents ni espaces). */
    private function fileName(string $prefix, string $value): string
    {
        $slug = Str::slug($value);

        return ($slug !== '' ? "{$prefix}-{$slug}" : $prefix).'.pdf';
    }
}
