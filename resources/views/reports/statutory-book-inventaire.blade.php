<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $bookLabel }}</title>
    <style>
        @page { margin: 96px 32px 56px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        header { position: fixed; top: -76px; left: 0; right: 0; text-align: center; font-size: 9px; }
        header .state { font-size: 7.5px; text-transform: uppercase; letter-spacing: .04em; }
        footer { position: fixed; bottom: -44px; left: 0; right: 0; font-size: 7.5px; text-align: center; color: #444; }
        .pagenum:before { content: counter(page); }
        h2 { font-size: 11px; margin: 18px 0 4px; border-bottom: 1px solid #333; padding-bottom: 2px; page-break-after: avoid; }
        h3 { font-size: 9px; margin: 8px 0 2px; page-break-after: avoid; }
        p.basis { font-size: 7.5px; color: #555; font-style: italic; margin: 2px 0 6px; }
        p.empty { font-size: 8.5px; color: #777; font-style: italic; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 1px solid #999; padding: 2px 4px; }
        th { background: #eaeaea; text-align: left; }
        td.num, th.num { text-align: right; font-family: DejaVu Sans Mono, monospace; white-space: nowrap; }
        tr.total td { font-weight: bold; background: #f3f3f3; }
        .section { page-break-inside: avoid; }
    </style>
</head>
<body>
<header>
    <div class="state">République du Cameroun — Paix · Travail · Patrie</div>
    <strong>{{ $schoolName }}</strong> — {{ $bookLabel }} — exercice {{ $fiscalYearCode }}<br>
    {{ $periodStart }} → {{ $periodEnd }}
</header>

<footer>
    Page <span class="pagenum"></span> — généré le {{ $generatedAt }} par {{ $generatedBy }}
    @if ($coteParaphe !== '') · Cote et paraphe : {{ $coteParaphe }} @endif
</footer>

@php
    $fmt = static fn (int $n): string => number_format($n, 0, ',', ' ');
@endphp

<div class="section">
    <h2>1. Bilan</h2>
    <p class="basis">{{ $bilan['basis'] }}</p>

    @if (! $bilan['has_data'])
        <p class="empty">Aucune donnée pour la période sélectionnée.</p>
    @else
        <h3>Actif</h3>
        <table>
            <thead><tr><th>Rubrique</th><th>Code</th><th>Intitulé</th><th class="num">Montant (FCFA)</th></tr></thead>
            <tbody>
            @foreach ($bilan['actif'] as $section)
                @foreach ($section['lines'] as $line)
                    <tr>
                        <td>{{ $section['label'] }}</td>
                        <td>{{ $line['code'] }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td class="num">{{ $fmt($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="3">Sous-total {{ $section['label'] }}</td><td class="num">{{ $fmt($section['total']) }}</td></tr>
            @endforeach
            <tr class="total"><td colspan="3">TOTAL ACTIF</td><td class="num">{{ $fmt($bilan['total_actif']) }}</td></tr>
            </tbody>
        </table>

        <h3>Passif</h3>
        <table>
            <thead><tr><th>Rubrique</th><th>Code</th><th>Intitulé</th><th class="num">Montant (FCFA)</th></tr></thead>
            <tbody>
            @foreach ($bilan['passif'] as $section)
                @foreach ($section['lines'] as $line)
                    <tr>
                        <td>{{ $section['label'] }}</td>
                        <td>{{ $line['code'] }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td class="num">{{ $fmt($line['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="3">Sous-total {{ $section['label'] }}</td><td class="num">{{ $fmt($section['total']) }}</td></tr>
            @endforeach
            <tr class="total"><td colspan="3">TOTAL PASSIF</td><td class="num">{{ $fmt($bilan['total_passif']) }}</td></tr>
            <tr class="total"><td colspan="3">ÉCART ACTIF − PASSIF</td><td class="num">{{ $fmt($bilan['difference']) }}</td></tr>
            </tbody>
        </table>

        @if ($bilan['excluded'] !== [])
            <h3>Hors bilan (classe 9)</h3>
            <table>
                <thead><tr><th>Code</th><th>Intitulé</th><th class="num">Montant (FCFA)</th></tr></thead>
                <tbody>
                @foreach ($bilan['excluded'] as $line)
                    <tr><td>{{ $line['code'] }}</td><td>{{ $line['name'] }}</td><td class="num">{{ $fmt($line['amount']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>

<div class="section">
    <h2>2. Compte de résultat</h2>
    <p class="basis">{{ $resultat['basis'] }}</p>

    @if (! $resultat['has_data'])
        <p class="empty">Aucune donnée pour la période sélectionnée.</p>
    @else
        <table>
            <thead><tr><th>Section</th><th>Code</th><th>Intitulé</th><th class="num">Montant (FCFA)</th></tr></thead>
            <tbody>
            @foreach ($resultat['produits'] as $line)
                <tr><td>Produits</td><td>{{ $line['code'] }}</td><td>{{ $line['name'] }}</td><td class="num">{{ $fmt($line['amount']) }}</td></tr>
            @endforeach
            <tr class="total"><td colspan="3">TOTAL PRODUITS</td><td class="num">{{ $fmt($resultat['total_produits']) }}</td></tr>
            @foreach ($resultat['charges'] as $line)
                <tr><td>Charges</td><td>{{ $line['code'] }}</td><td>{{ $line['name'] }}</td><td class="num">{{ $fmt($line['amount']) }}</td></tr>
            @endforeach
            <tr class="total"><td colspan="3">TOTAL CHARGES</td><td class="num">{{ $fmt($resultat['total_charges']) }}</td></tr>
            <tr class="total"><td colspan="3">RÉSULTAT NET</td><td class="num">{{ $fmt($resultat['net']) }}</td></tr>
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>3. Tableau des flux de trésorerie</h2>
    <p class="basis">{{ $flux['basis'] }}</p>

    @if (! $flux['has_data'])
        <p class="empty">Aucune donnée pour la période sélectionnée.</p>
    @else
        <table>
            <thead>
            <tr><th>Code</th><th>Compte</th><th class="num">Solde ouverture</th><th class="num">Encaissements</th><th class="num">Décaissements</th><th class="num">Solde clôture</th></tr>
            </thead>
            <tbody>
            @foreach ($flux['lines'] as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="num">{{ $fmt($line['opening']) }}</td>
                    <td class="num">{{ $fmt($line['inflow']) }}</td>
                    <td class="num">{{ $fmt($line['outflow']) }}</td>
                    <td class="num">{{ $fmt($line['closing']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">TOTAL TRÉSORERIE</td>
                <td class="num">{{ $fmt($flux['opening']) }}</td>
                <td class="num">{{ $fmt($flux['inflows']) }}</td>
                <td class="num">{{ $fmt($flux['outflows']) }}</td>
                <td class="num">{{ $fmt($flux['closing']) }}</td>
            </tr>
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>4. Inventaire physique — stocks</h2>
    <p class="basis">{{ $stock['basis'] }}</p>

    @if (! $stock['has_data'])
        <p class="empty">Aucun inventaire physique approuvé pour cet exercice.</p>
    @else
        <h3>Comptages</h3>
        <table>
            <thead><tr><th>Référence</th><th>Date</th><th>Magasin</th><th>Inventaire complet</th></tr></thead>
            <tbody>
            @foreach ($stock['takes'] as $take)
                <tr>
                    <td>{{ $take['reference'] }}</td>
                    <td>{{ $take['count_date'] }}</td>
                    <td>{{ $take['store_location'] }}</td>
                    <td>{{ $take['is_full_count'] ? 'Oui' : 'Non' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <h3>Articles</h3>
        <table>
            <thead>
            <tr><th>Code</th><th>Article</th><th class="num">Qté système</th><th class="num">Qté comptée</th><th class="num">Écart</th><th class="num">Valeur système (FCFA)</th><th class="num">Écart valeur (FCFA)</th></tr>
            </thead>
            <tbody>
            @foreach ($stock['lines'] as $line)
                <tr>
                    <td>{{ $line['item_code'] }}</td>
                    <td>{{ $line['item_name'] }}</td>
                    <td class="num">{{ $line['system_quantity'] }}</td>
                    <td class="num">{{ $line['counted_quantity'] ?? '—' }}</td>
                    <td class="num">{{ $line['variance_quantity'] ?? '—' }}</td>
                    <td class="num">{{ $fmt($line['system_value']) }}</td>
                    <td class="num">{{ $line['variance_value'] === null ? '—' : $fmt($line['variance_value']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="5">TOTAL</td>
                <td class="num">{{ $fmt($stock['total_system_value']) }}</td>
                <td class="num">{{ $fmt($stock['total_variance_value']) }}</td>
            </tr>
            </tbody>
        </table>
    @endif
</div>

<div class="section">
    <h2>5. Inventaire des immobilisations</h2>
    <p class="basis">{{ $assets['basis'] }}</p>

    @if (! $assets['has_data'])
        <p class="empty">Aucune immobilisation dans le registre pour cet exercice.</p>
    @else
        <table>
            <thead>
            <tr><th>N° tag</th><th>Désignation</th><th>Date d'acquisition</th><th class="num">Coût d'acquisition (FCFA)</th><th class="num">Amortissement cumulé (FCFA)</th><th class="num">Valeur nette comptable (FCFA)</th></tr>
            </thead>
            <tbody>
            @foreach ($assets['lines'] as $line)
                <tr>
                    <td>{{ $line['tag_number'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['acquisition_date'] }}</td>
                    <td class="num">{{ $fmt($line['acquisition_cost']) }}</td>
                    <td class="num">{{ $fmt($line['accumulated_depreciation']) }}</td>
                    <td class="num">{{ $fmt($line['net_book_value']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">TOTAL</td>
                <td class="num">{{ $fmt($assets['total_acquisition_cost']) }}</td>
                <td class="num">{{ $fmt($assets['total_accumulated_depreciation']) }}</td>
                <td class="num">{{ $fmt($assets['total_net_book_value']) }}</td>
            </tr>
            </tbody>
        </table>
    @endif
</div>
</body>
</html>
