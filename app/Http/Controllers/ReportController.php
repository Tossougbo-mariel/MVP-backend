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

class ReportController extends Controller
{
    public function project(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        $locale = $this->locale($request);
        App::setLocale($locale);

        $data = (new ProjectReportBuilder($project))->build();
        $prefix = $locale === 'en' ? 'project-report' : 'rapport-projet';

        return $this->pdf($data)
            ->download($this->fileName($prefix, $project->name));
    }

    public function agency(Request $request, Agency $agency)
    {
        $this->authorize('view', $agency);
        $locale = $this->locale($request);
        App::setLocale($locale);

        $data = (new AgencyReportBuilder($agency))->build();
        $prefix = $locale === 'en' ? 'agency-report' : 'rapport-agence';

        return $this->pdf($data)
            ->download($this->fileName($prefix, $agency->name));
    }

    /** Locale du rapport : fr par defaut, en uniquement si demande. */
    private function locale(Request $request): string
    {
        $locale = (string) $request->query('locale', '');

        return in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
    }

    /** Rendu PDF partage, avec pagination canvas pour toutes les pages. */
    private function pdf(array $data)
    {
        $label = ! empty($data['subtitle'])
            ? "{$data['title']} : {$data['subtitle']}"
            : $data['title'];
        $pageLabel = __('reports.page');

        $pdf = Pdf::loadView('reports.report', $data)->setPaper('a4');
        $pdf->render();

        $pdf->getDomPDF()->getCanvas()->page_script(
            function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($label, $pageLabel) {
                $font = $fontMetrics->getFont('helvetica');
                $size = 8.25;
                $color = [0.42, 0.447, 0.502];
                $left = 62.4;
                $right = 595.28 - 62.4;
                $y = 799.2;

                $canvas->text($left, $y, $label, $font, $size, $color);

                $page = "{$pageLabel} {$pageNumber} / {$pageCount}";
                $width = $fontMetrics->getTextWidth($page, $font, $size);
                $canvas->text($right - $width, $y, $page, $font, $size, $color);
            }
        );

        return $pdf;
    }

    private function fileName(string $prefix, string $value): string
    {
        $slug = Str::slug($value);

        return ($slug !== '' ? "{$prefix}-{$slug}" : $prefix).'.pdf';
    }
}
