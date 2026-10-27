<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    /* Une seule couleur d'accent (bleu marine professionnel) */
    @page { margin: 44px 44px 58px 44px; }

    * { font-family: 'DejaVu Sans', sans-serif; }

    body { margin: 0; color: #23272e; font-size: 10px; line-height: 1.45; }

    /* Bandeau superieur : rappel discret de la couleur d'accent */
    .topband { height: 6px; background: #1f3864; margin-bottom: 18px; }

    /* En-tete du document */
    .head { margin-bottom: 22px; }
    h1 { font-size: 19px; color: #1f3864; margin: 0 0 5px 0; letter-spacing: 0.2px; }
    .subtitle { font-size: 11px; color: #444b55; margin-bottom: 3px; }
    .generated { font-size: 8.5px; color: #8a9099; }

    .description {
        border-left: 3px solid #1f3864;
        background: #f5f7fb;
        padding: 8px 12px;
        margin: 0 0 22px 0;
        font-size: 9.5px;
        color: #333a44;
    }

    /* Sections */
    .section { margin-bottom: 22px; }
    h2 {
        font-size: 12px;
        color: #1f3864;
        border-bottom: 2px solid #1f3864;
        padding-bottom: 4px;
        margin: 0 0 10px 0;
        page-break-after: avoid;
    }

    table { width: 100%; border-collapse: collapse; }

    /* Liste cle / valeur */
    table.kv td { padding: 5px 2px; font-size: 9.5px; vertical-align: top; border-bottom: 1px solid #edf0f5; }
    table.kv td.k { width: 66%; color: #5a6472; }
    table.kv td.v { text-align: right; font-weight: bold; color: #23272e; }

    /* Tableaux de donnees */
    table.grid { border: 1px solid #d6ddea; }
    table.grid th {
        background: #e9edf5;
        color: #1f3864;
        border: 1px solid #c6cfdf;
        padding: 6px 7px;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        text-align: left;
    }
    table.grid td {
        border: 1px solid #e0e6f0;
        padding: 5px 7px;
        font-size: 9px;
        vertical-align: top;
    }
    table.grid tbody tr { page-break-inside: avoid; }
    table.grid tfoot td { background: #f5f7fb; font-weight: bold; }

    .num { text-align: right; }
    .center { text-align: center; }
    .muted { color: #8a9099; }

    ol.actions { margin: 0; padding-left: 16px; }
    ol.actions li { margin-bottom: 4px; font-size: 9.5px; }

    /* Pied de page repete, avec numerotation */
</style>
</head>
<body>
<div class="topband"></div>

    <div class="head">
        <h1>{{ $title }}</h1>
        @if (! empty($subtitle))
            <div class="subtitle">{{ $subtitle }}</div>
        @endif
        <div class="generated">{{ __('reports.generatedOn') }} {{ $generatedAt }}</div>
    </div>

    @if (! empty($description))
        <div class="description">{{ $description }}</div>
    @endif

    @foreach ($sections as $section)
        <div class="section">
            <h2>{{ $section['heading'] }}</h2>

            @if ($section['type'] === 'keyvalue')
                <table class="kv">
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td class="k">{{ $row['label'] }}</td>
                            <td class="v">{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>

            @elseif ($section['type'] === 'table')
                <table class="grid">
                    <thead>
                        <tr>
                            @foreach ($section['columns'] as $col)
                                <th>{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            <tr>
                                @foreach ($row as $i => $cell)
                                    <td class="{{ $section['align'][$i] ?? '' }}">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($section['columns']) }}" class="center muted">{{ __('reports.noData') }}</td>
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
