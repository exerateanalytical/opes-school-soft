<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Documentation du système comptable</title>
    <style>
        @page { margin: 90px 32px 56px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        header { position: fixed; top: -76px; left: 0; right: 0; text-align: center; font-size: 9px; }
        footer { position: fixed; bottom: -44px; left: 0; right: 0; font-size: 7.5px; text-align: center; color: #444; }
        .pagenum:before { content: counter(page); }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #999; padding: 2px 4px; }
        th { background: #eaeaea; text-align: left; }
        td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .meta { font-size: 8px; color: #444; }
    </style>
</head>
<body>
<header>
    <strong>{{ $schoolName }}</strong> — Documentation du système comptable (AUDCIF §14.4)
</header>
<footer>
    Page <span class="pagenum"></span> — généré le {{ $generatedAt }} par {{ $generatedBy }}
</footer>

<h2>Système</h2>
<table>
    <tr><th>Version logicielle</th><td>{{ $softwareVersion }}</td></tr>
    <tr><th>Version du schéma (dernière migration)</th><td>{{ $schemaVersion }}</td></tr>
</table>

<h2>Rôles disposant de droits comptables</h2>
<p>{{ $accountingRoles->implode(', ') }}</p>

<h2>Plan des comptes ({{ $accounts->count() }} comptes)</h2>
<table>
    <thead><tr><th>Code</th><th>Intitulé</th><th>Classe</th><th>Postable</th></tr></thead>
    <tbody>
    @foreach ($accounts as $a)
        <tr><td>{{ $a->code }}</td><td>{{ $a->name }}</td><td>{{ $a->account_class }}</td><td>{{ $a->is_postable ? 'Oui' : 'Non' }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Journaux</h2>
<table>
    <thead><tr><th>Code</th><th>Intitulé</th><th>Maker-checker</th><th>Format de pièce</th></tr></thead>
    <tbody>
    @foreach ($journals as $j)
        <tr><td>{{ $j->code }}</td><td>{{ $j->name }}</td><td>{{ $j->requires_maker_checker ? 'Oui' : 'Non' }}</td><td>{{ $j->piece_no_format }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Règles de comptabilisation actives ({{ $postingRules->count() }})</h2>
<table>
    <thead><tr><th>Code</th><th>Version</th><th>Événement</th><th>Condition</th><th>Verrouillée</th><th>Effective du</th><th>au</th></tr></thead>
    <tbody>
    @foreach ($postingRules as $r)
        <tr>
            <td>{{ $r->code }}</td>
            <td>{{ $r->version }}</td>
            <td>{{ $r->event }}</td>
            <td>{{ $r->condition_expression ?? '—' }}</td>
            <td>{{ $r->is_locked ? 'Oui' : 'Non' }}</td>
            <td>{{ $r->effective_from }}</td>
            <td>{{ $r->effective_to ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Lignes des règles de comptabilisation</h2>
<table>
    <thead><tr><th>Règle</th><th>Séq.</th><th>Source du compte</th><th>Compte / chemin</th><th>Signe</th><th>Expression du montant</th></tr></thead>
    <tbody>
    @foreach ($postingRuleLines as $l)
        <tr>
            <td>{{ $l->posting_rule_id }}</td>
            <td>{{ $l->sequence }}</td>
            <td>{{ $l->account_source }}</td>
            <td>{{ $l->account_code ?? $l->account_path }}</td>
            <td>{{ $l->sign }}</td>
            <td>{{ $l->amount_expression }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Axes analytiques</h2>
<table>
    <thead><tr><th>Code</th><th>Nom</th><th>Obligatoire</th><th>Classes concernées</th></tr></thead>
    <tbody>
    @foreach ($analyticAxes as $ax)
        <tr><td>{{ $ax->code }}</td><td>{{ $ax->name }}</td><td>{{ $ax->is_mandatory ? 'Oui' : 'Non' }}</td><td>{{ $ax->applies_to_classes }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Verrouillage des périodes (24 dernières)</h2>
<table>
    <thead><tr><th>Période</th><th>Statut</th><th>Verrouillage souple</th><th>Verrouillage dur</th></tr></thead>
    <tbody>
    @foreach ($periods as $p)
        <tr><td>{{ $p->period_month }}</td><td>{{ $p->status }}</td><td>{{ $p->soft_locked_at ?? '—' }}</td><td>{{ $p->hard_locked_at ?? '—' }}</td></tr>
    @endforeach
    </tbody>
</table>

<h2>Formats de séquence</h2>
<table>
    <thead><tr><th>Série</th><th>Prochaine valeur</th></tr></thead>
    <tbody>
    @foreach ($sequences as $s)
        <tr><td>{{ $s->series }}</td><td class="num">{{ $s->next_value }}</td></tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
