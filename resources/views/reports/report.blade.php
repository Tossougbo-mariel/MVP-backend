<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    /* Design aligné sur la maquette « rapport-agence-new » :
       en-tête centré, titres marine, tableaux à en-tête plein
       et lignes alternées blanc / bleu pâle, sans grille verticale. */
    @page { margin: 8mm 22mm 14mm 22mm; }

    * { font-family: Helvetica, Arial, sans-serif; }

    body { margin: 0; color: #222222; font-size: 12.5px; line-height: 1.35; }

    /* En-tete du document (centre, rapproche du haut de page) */
    .head { padding: 2mm 0 0 0; text-align: center; }
    h1 { font-size: 29px; font-weight: bold; color: #1f3a5f; margin: 0 0 5px 0; }
    .subtitle { font-size: 13px; color: #6b7280; }

    /* Sections */
    .section { margin: 0 0 14px 0; }
    h2 {
        font-size: 17px;
        font-weight: bold;
        color: #1f3a5f;
        margin: 0 0 9px 0;
        page-break-after: avoid;
    }
    p.intro { margin: 0; font-size: 12.5px; color: #222222; }

    table { width: 100%; border-collapse: collapse; }

    /* Liste cle / valeur : etiquette sur pastille bleu pale, valeur a droite,
       filets pleine largeur entre les lignes */
    table.kv td {
        padding: 6px 8px;
        font-size: 12.5px;
        vertical-align: top;
        border-bottom: 1px solid #c9d3e0;
    }
    table.kv td.k { width: 28%; background: #e8eef6; font-weight: bold; }

    /* Tableaux de donnees : en-tete bleu adouci, lignes alternees, filets
       horizontaux uniquement */
    table.grid th {
        background: #5b7ba6;
        color: #ffffff;
        padding: 7px 8px;
        font-size: 12px;
        font-weight: bold;
        text-align: left;
        border-bottom: 1px solid #c9d3e0;
    }
    table.grid td {
        padding: 7px 8px;
        font-size: 12.5px;
        border-bottom: 1px solid #c9d3e0;
        vertical-align: top;
    }
    table.grid tbody tr.alt td { background: #e8eef6; }
    table.grid tbody tr { page-break-inside: avoid; }
    table.grid tfoot td {
        background: #f3f6fa;
        font-weight: bold;
        border-top: 1px solid #c9d3e0;
    }

    .num { text-align: right; }
    .center { text-align: center; }
    .muted { color: #6b7280; }

    table.grid th.num, table.grid td.num { text-align: right; }
    table.grid th.center, table.grid td.center { text-align: center; }

    ol.actions { margin: 0; padding-left: 16px; }
    ol.actions li { margin-bottom: 4px; font-size: 12.5px; }
</style>
</head>
<body>

    <div class="head">
        <h1>{{ $title }}</h1>
        <div class="subtitle">@if (! empty($subtitle)){{ $subtitle }} &nbsp;|&nbsp; @endif Généré le {{ $generatedAt }}</div>
    </div>

    @if (! empty($description))
        <div class="section">
            <h2>Présentation</h2>
            <p class="intro">{{ $description }}</p>
        </div>
    @endif

    @foreach ($sections as $section)
        <div class="section">
            <h2>{{ $section['heading'] }}</h2>

            @if ($section['type'] === 'keyvalue')
                <table class="kv">
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td class="k">{{ $row['label'] }}</td>
                            <td>{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>

            @elseif ($section['type'] === 'table')
                <table class="grid">
                    <thead>
                        <tr>
                            @foreach ($section['columns'] as $i => $col)
                                <th class="{{ $section['align'][$i] ?? '' }}">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            <tr class="{{ $loop->index % 2 === 1 ? 'alt' : '' }}">
                                @foreach ($row as $i => $cell)
                                    <td class="{{ $section['align'][$i] ?? '' }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($section['columns']) }}" class="center muted">Aucune donnée.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if (! empty($section['footer']))
                        <tfoot>
                            <tr>
                                @foreach ($section['footer'] as $i => $cell)
                                    <td class="{{ $section['align'][$i] ?? '' }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>

            @elseif ($section['type'] === 'text')
                <ol class="actions">
                    @foreach ($section['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>
            @endif
        </div>
    @endforeach

</body>
</html>
