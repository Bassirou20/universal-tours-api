<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Devis {{ $devis['numero'] ?? '' }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans, sans-serif; font-size:10px; color:#1a1a2e; background:#fff; line-height:1.4; }

.top-bar { background:#0f3460; height:5px; width:100%; }
.page { padding:16px 28px 55px 28px; }

/* Header */
.hdr { width:100%; border-collapse:collapse; margin-bottom:14px; }
.hdr-logo { width:180px; vertical-align:middle; }
.hdr-logo img { width:150px; max-height:48px; }
.hdr-logo .brand { font-size:15px; font-weight:bold; color:#0f3460; letter-spacing:1px; }
.hdr-info { vertical-align:middle; text-align:right; font-size:9px; color:#555; line-height:1.55; }
.hdr-info .co-name { font-size:11px; font-weight:bold; color:#0f3460; display:block; margin-bottom:1px; }

.divider { border:none; border-top:2px solid #0f3460; margin-bottom:12px; }

/* Title */
.title-tbl { width:100%; border-collapse:collapse; margin-bottom:12px; }
.doc-title { font-size:18px; font-weight:bold; color:#0f3460; }
.doc-sub { font-size:9px; color:#888; margin-top:2px; }
.status-badge {
    display:inline-block; padding:4px 10px; border-radius:3px;
    font-size:10px; font-weight:bold; letter-spacing:.4px;
}
.badge-paid    { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-partial { background:#fff3cd; color:#856404; border:1px solid #ffeaa7; }
.badge-unpaid  { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Info boxes */
.info-tbl { width:100%; border-collapse:collapse; margin-bottom:12px; }
.info-box { border:1px solid #dde3ed; background:#f7f9fc; padding:9px 12px; vertical-align:top; width:50%; }
.info-box .box-title {
    font-size:8.5px; font-weight:bold; color:#0f3460;
    text-transform:uppercase; letter-spacing:.8px;
    margin-bottom:5px; border-bottom:1px solid #dde3ed; padding-bottom:4px;
}
.info-box table { width:100%; border-collapse:collapse; }
.info-box table td { border:none; padding:2px 0; vertical-align:top; }
.info-box table td:first-child { color:#888; width:80px; }

/* KPIs */
.kpi-row { width:100%; border-collapse:collapse; margin-bottom:12px; }
.kpi { border:1px solid #dde3ed; padding:7px 10px; vertical-align:top; text-align:center; }
.kpi .k-label { font-size:8.5px; color:#888; }
.kpi .k-value { font-size:11px; font-weight:bold; color:#0f3460; margin-top:2px; }

/* Section title */
.section-title {
    font-size:9px; font-weight:bold; color:#0f3460;
    background:#f0f4fa; padding:5px 8px;
    border-left:3px solid #0f3460;
    margin-bottom:5px; margin-top:10px;
    text-transform:uppercase; letter-spacing:.4px;
}

/* Data tables */
table.data { width:100%; border-collapse:collapse; margin-bottom:8px; }
table.data th {
    background:#0f3460; color:#fff; padding:5px 7px;
    text-align:left; font-size:9px; font-weight:bold;
}
table.data td { border-bottom:1px solid #eee; padding:5px 7px; vertical-align:top; font-size:9.5px; }
table.data tr:nth-child(even) td { background:#f8f9fc; }

/* Totals */
.totals-wrap { width:100%; border-collapse:collapse; margin-top:8px; margin-bottom:10px; }
.totals-spacer { width:55%; }
.totals-box { width:45%; vertical-align:top; }
.totals-table { width:100%; border-collapse:collapse; border:1px solid #dde3ed; }
.totals-table td { padding:5px 10px; border-bottom:1px solid #eef1f6; font-size:10px; }
.totals-table .lbl { color:#555; }
.totals-table .val { text-align:right; font-weight:bold; }
.totals-table .total-row { background:#0f3460; }
.totals-table .total-row td { color:#fff; border-bottom:none; }
.totals-table .paid-row td { color:#155724; background:#d4edda; }
.totals-table .due-row td  { color:#856404; background:#fff3cd; font-weight:bold; }

/* Note */
.note {
    border:1px dashed #aab; padding:8px 12px; font-size:9px;
    color:#555; line-height:1.55; margin-bottom:10px; border-radius:2px;
}

/* Signature */
.sig { text-align:right; font-size:10px; margin-top:14px; }
.sig-line { border-top:1px solid #ccc; width:150px; margin:20px 0 0 auto; }

/* Footer */
.footer {
    position:fixed; bottom:0; left:0; right:0;
    background:#0f3460; color:#fff; font-size:8.5px; padding:6px 28px;
}
.footer-inner { width:100%; border-collapse:collapse; }
.footer-left  { color:#b8c9e4; }
.footer-right { text-align:right; color:#b8c9e4; }
.footer-center { text-align:center; color:#fff; font-weight:bold; }
</style>
</head>
<body>

@php
  $client = $reservation->client ?? null;
  $type   = $reservation->type ?? '';
  $ref    = $reservation->reference ?? ('RES-' . str_pad($reservation->id, 6, '0', STR_PAD_LEFT));

  $numero  = $devis['numero'] ?? '';
  $dateDoc = $devis['date'] ?? now()->toDateString();
  $validite = $devis['validite'] ?? null;

  $total = (float)($montant_total ?? 0);
  $sous  = (float)($reservation->montant_sous_total ?? 0);
  $taxes = (float)($reservation->montant_taxes ?? 0);

  $payLabel   = $pay['label']   ?? 'NON PAYÉ';
  $paid       = (float)($pay['paid']      ?? 0);
  $remaining  = (float)($pay['remaining'] ?? $total);
  $payPercent = (int)($pay['percent']   ?? 0);

  $badgeClass = match($payLabel) {
    'PAYÉ' => 'badge-paid',
    'PARTIELLEMENT PAYÉ' => 'badge-partial',
    default => 'badge-unpaid',
  };

  $typeLabels = [
    'billet_avion' => "Billet d'avion",
    'hotel'        => 'Hôtel',
    'voiture'      => 'Location voiture',
    'evenement'    => 'Événement',
    'forfait'      => 'Forfait',
    'assurance'    => 'Assurance',
    'evisa'        => 'E-Visa / Assistance visa',
  ];
  $typeLabel = $typeLabels[$type] ?? ucfirst($type);

  $flight    = $reservation->flightDetails ?? null;
  $assurance = $reservation->assuranceDetails ?? null;
  $evisa     = $reservation->evisaDetails ?? null;
  $produit   = $reservation->produit ?? null;
  $forfait   = $reservation->forfait ?? null;

  $allParts    = $reservation->participants ?? collect();
  $passengerRoles = ['passenger','passager','demandeur','beneficiary','beneficiaire'];
  $passengers  = $allParts->filter(fn($p) => in_array(strtolower($p->role ?? ''), $passengerRoles));
  $participants = $allParts->filter(fn($p) => !in_array(strtolower($p->role ?? ''), $passengerRoles));
@endphp

<div class="top-bar"></div>
<div class="page">

{{-- Header --}}
<table class="hdr">
  <tr>
    <td class="hdr-logo">
      @if($logoPath && file_exists($logoPath))
        <img src="{{ $logoPath }}" alt="Universal Tours">
      @else
        <div class="brand">UNIVERSAL TOURS</div>
      @endif
    </td>
    <td class="hdr-info">
      <span class="co-name">UNIVERSAL TOURS SARL</span>
      Villa n°D40, Cité BCEAO – Nord Foire – Dakar, Sénégal<br>
      NINEA : 010321503 &nbsp;|&nbsp; RC : SN.DKR.2023 B.22726<br>
      Tél : +221 77 579 96 01 &nbsp;|&nbsp; universaltoursn@gmail.com
    </td>
  </tr>
</table>

<hr class="divider">

{{-- Titre --}}
<table class="title-tbl">
  <tr>
    <td>
      <div class="doc-title">DEVIS / FACTURE PRO FORMA</div>
      <div class="doc-sub">N° {{ $numero }} &nbsp;·&nbsp; {{ \Carbon\Carbon::parse($dateDoc)->format('d/m/Y') }}</div>
    </td>
    <td style="text-align:right; vertical-align:top;">
      <span class="status-badge {{ $badgeClass }}">{{ $payLabel }}</span>
    </td>
  </tr>
</table>

{{-- Infos devis + client --}}
<table class="info-tbl">
  <tr>
    <td class="info-box" style="padding-right:8px;">
      <div class="box-title">Informations devis</div>
      <table>
        <tr><td>N° devis</td><td><strong>{{ $numero }}</strong></td></tr>
        <tr><td>Réservation</td><td><strong>{{ $ref }}</strong></td></tr>
        <tr><td>Type</td><td>{{ $typeLabel }}</td></tr>
        <tr><td>Date</td><td>{{ \Carbon\Carbon::parse($dateDoc)->format('d/m/Y') }}</td></tr>
        @if($validite)<tr><td>Validité</td><td>{{ \Carbon\Carbon::parse($validite)->format('d/m/Y') }}</td></tr>@endif
        <tr><td>Nb personnes</td><td>{{ $reservation->nombre_personnes ?? 1 }}</td></tr>
      </table>
    </td>
    <td style="width:14px;"></td>
    <td class="info-box">
      <div class="box-title">Client / Payeur</div>
      @if($client)
        <table>
          <tr><td>Nom</td><td><strong>{{ trim(($client->nom ?? '') . ' ' . ($client->prenom ?? '')) }}</strong></td></tr>
          @if($client->telephone)<tr><td>Tél</td><td>{{ $client->telephone }}</td></tr>@endif
          @if($client->email)<tr><td>Email</td><td>{{ $client->email }}</td></tr>@endif
          @if($client->adresse)<tr><td>Adresse</td><td>{{ $client->adresse }}</td></tr>@endif
          @if($client->pays)<tr><td>Pays</td><td>{{ $client->pays }}</td></tr>@endif
        </table>
      @else
        <span style="color:#aaa;">Non renseigné</span>
      @endif
    </td>
  </tr>
</table>

{{-- KPIs --}}
<table class="kpi-row">
  <tr>
    <td class="kpi" style="width:25%">
      <div class="k-label">Total</div>
      <div class="k-value">{{ number_format($total, 0, ',', ' ') }} FCFA</div>
    </td>
    <td style="width:2%"></td>
    <td class="kpi" style="width:25%">
      <div class="k-label">Payé</div>
      <div class="k-value" style="color:{{ $paid > 0 ? '#155724' : '#721c24' }}">
        {{ number_format($paid, 0, ',', ' ') }} FCFA
      </div>
    </td>
    <td style="width:2%"></td>
    <td class="kpi" style="width:25%">
      <div class="k-label">Reste à payer</div>
      <div class="k-value" style="color:{{ $remaining > 0 ? '#856404' : '#155724' }}">
        {{ number_format($remaining, 0, ',', ' ') }} FCFA
      </div>
    </td>
    <td style="width:2%"></td>
    <td class="kpi" style="width:19%">
      <div class="k-label">% payé</div>
      <div class="k-value">{{ $payPercent }}%</div>
    </td>
  </tr>
</table>

{{-- ══ SECTION PAR TYPE ══ --}}

@if($type === 'billet_avion')
  @if($flight)
  <div class="section-title">Détails du vol</div>
  <table class="data">
    <thead>
      <tr><th>Départ</th><th>Arrivée</th><th>Date départ</th><th>Date arrivée</th><th>Compagnie</th><th>PNR</th><th>Classe</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>{{ $flight->ville_depart ?? '—' }}</td>
        <td>{{ $flight->ville_arrivee ?? '—' }}</td>
        <td>{{ $flight->date_depart ? \Carbon\Carbon::parse($flight->date_depart)->format('d/m/Y') : '—' }}</td>
        <td>{{ $flight->date_arrivee ? \Carbon\Carbon::parse($flight->date_arrivee)->format('d/m/Y') : '—' }}</td>
        <td>{{ $flight->compagnie ?? '—' }}</td>
        <td>{{ $flight->pnr ?? '—' }}</td>
        <td>{{ $flight->classe ?? '—' }}</td>
      </tr>
    </tbody>
  </table>
  @endif
  @if($passengers->count())
  <div class="section-title">Passagers ({{ $passengers->count() }})</div>
  <table class="data">
    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Passeport</th><th>Sexe</th></tr></thead>
    <tbody>
      @foreach($passengers as $i => $p)
      <tr>
        <td>{{ $i+1 }}</td><td>{{ $p->nom ?? '—' }}</td><td>{{ $p->prenom ?? '—' }}</td>
        <td style="font-size:8.5px;">{{ $p->passport ?? '—' }}</td><td>{{ $p->sexe ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

@elseif($type === 'evisa')
  @if($evisa)
  <div class="section-title">Détails de la demande E-Visa</div>
  <table class="data">
    <thead><tr><th>Pays destination</th><th>Type de visa</th><th>Date de voyage</th><th>Durée de séjour</th></tr></thead>
    <tbody>
      <tr>
        <td>{{ $evisa->pays_destination ?? '—' }}</td>
        <td>{{ $evisa->type_visa ?? '—' }}</td>
        <td>{{ $evisa->date_voyage ? \Carbon\Carbon::parse($evisa->date_voyage)->format('d/m/Y') : '—' }}</td>
        <td>{{ $evisa->duree_sejour ?? '—' }}</td>
      </tr>
    </tbody>
  </table>
  @endif
  @if($passengers->count())
  <div class="section-title">Demandeurs ({{ $passengers->count() }})</div>
  <table class="data">
    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Passeport</th></tr></thead>
    <tbody>
      @foreach($passengers as $i => $p)
      <tr>
        <td>{{ $i+1 }}</td><td>{{ $p->nom ?? '—' }}</td>
        <td>{{ $p->prenom ?? '—' }}</td><td style="font-size:8.5px;">{{ $p->passport ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

@elseif($type === 'assurance')
  @if($assurance)
  <div class="section-title">Détails de l'assurance</div>
  <table class="data">
    <thead><tr><th>Libellé</th><th>Date début</th><th>Date fin</th></tr></thead>
    <tbody>
      <tr>
        <td>{{ $assurance->libelle ?? '—' }}</td>
        <td>{{ $assurance->date_debut ? \Carbon\Carbon::parse($assurance->date_debut)->format('d/m/Y') : '—' }}</td>
        <td>{{ $assurance->date_fin ? \Carbon\Carbon::parse($assurance->date_fin)->format('d/m/Y') : '—' }}</td>
      </tr>
    </tbody>
  </table>
  @endif
  @if($passengers->count())
  <div class="section-title">Bénéficiaire(s)</div>
  <table class="data">
    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Passeport</th></tr></thead>
    <tbody>
      @foreach($passengers as $i => $p)
      <tr>
        <td>{{ $i+1 }}</td><td>{{ $p->nom ?? '—' }}</td>
        <td>{{ $p->prenom ?? '—' }}</td><td style="font-size:8.5px;">{{ $p->passport ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

@elseif($type === 'forfait')
  @if($forfait)
  <div class="section-title">Forfait</div>
  <table class="data">
    <thead><tr><th>Nom</th><th>Description</th></tr></thead>
    <tbody>
      <tr><td><strong>{{ $forfait->nom ?? '—' }}</strong></td><td>{{ $forfait->description ?? '—' }}</td></tr>
    </tbody>
  </table>
  @endif
  @if($participants->count())
  <div class="section-title">Participants ({{ $participants->count() }})</div>
  <table class="data">
    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Âge</th><th>Passeport</th><th>Remarques</th></tr></thead>
    <tbody>
      @foreach($participants as $i => $p)
      <tr>
        <td>{{ $i+1 }}</td><td>{{ $p->nom ?? '—' }}</td><td>{{ $p->prenom ?? '—' }}</td>
        <td>{{ $p->age ?? '—' }}</td><td style="font-size:8.5px;">{{ $p->passport ?? '—' }}</td>
        <td style="font-size:8.5px; color:#666;">{{ $p->remarques ?? '' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

@elseif(in_array($type, ['hotel','voiture','evenement']))
  @if($produit)
  <div class="section-title">{{ $type === 'hotel' ? 'Hébergement' : ($type === 'voiture' ? 'Véhicule' : 'Événement') }}</div>
  <table class="data">
    <thead><tr><th>Nom</th><th>Description</th><th>Localisation</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>{{ $produit->nom ?? '—' }}</strong></td>
        <td>{{ $produit->description ?? '—' }}</td>
        <td>{{ $produit->localisation ?? '—' }}</td>
      </tr>
    </tbody>
  </table>
  @endif
  @if($type === 'evenement' && $participants->count())
  <div class="section-title">Participants ({{ $participants->count() }})</div>
  <table class="data">
    <thead><tr><th>#</th><th>Nom</th><th>Prénom</th><th>Âge</th><th>Passeport</th></tr></thead>
    <tbody>
      @foreach($participants as $i => $p)
      <tr>
        <td>{{ $i+1 }}</td><td>{{ $p->nom ?? '—' }}</td><td>{{ $p->prenom ?? '—' }}</td>
        <td>{{ $p->age ?? '—' }}</td><td style="font-size:8.5px;">{{ $p->passport ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
@endif

{{-- Notes --}}
@if($reservation->notes)
<div class="section-title">Notes</div>
<div style="padding:6px 10px; background:#f8f9fc; border:1px solid #eee; font-size:9.5px; border-radius:2px; margin-bottom:8px;">{{ $reservation->notes }}</div>
@endif

{{-- Totaux --}}
<table class="totals-wrap">
  <tr>
    <td class="totals-spacer"></td>
    <td class="totals-box">
      <table class="totals-table">
        @if($sous > 0 || $taxes > 0)
        <tr><td class="lbl">Sous-total HT</td><td class="val">{{ number_format($sous, 0, ',', ' ') }} FCFA</td></tr>
        <tr><td class="lbl">Taxes / Frais</td><td class="val">{{ number_format($taxes, 0, ',', ' ') }} FCFA</td></tr>
        @endif
        <tr class="total-row">
          <td class="lbl" style="font-weight:bold;">Total TTC</td>
          <td class="val">{{ number_format($total, 0, ',', ' ') }} FCFA</td>
        </tr>
        @if($paid > 0)
        <tr class="paid-row">
          <td class="lbl">Montant payé ({{ $payPercent }}%)</td>
          <td class="val">{{ number_format($paid, 0, ',', ' ') }} FCFA</td>
        </tr>
        <tr class="due-row">
          <td class="lbl">Reste à payer</td>
          <td class="val">{{ number_format($remaining, 0, ',', ' ') }} FCFA</td>
        </tr>
        @endif
      </table>
    </td>
  </tr>
</table>

{{-- Mention légale --}}
<div class="note">
  <strong>Important :</strong> Ce document est un <strong>devis / pro forma</strong> et <strong>n'a pas valeur de facture fiscale</strong>.
  @if($validite) Validité jusqu'au <strong>{{ \Carbon\Carbon::parse($validite)->format('d/m/Y') }}</strong>. @endif
  Les prestations seront confirmées après validation et, si applicable, versement d'un acompte.
</div>

{{-- Signature --}}
<div class="sig">
  <strong>La Direction – Universal Tours</strong><br>
  <span style="color:#aaa; font-size:9px;">Signature &amp; Cachet</span>
  <div class="sig-line"></div>
</div>

</div>

<div class="footer">
  <table class="footer-inner">
    <tr>
      <td class="footer-left">Universal Tours SARL – NINEA 010321503</td>
      <td class="footer-center">Merci pour votre confiance</td>
      <td class="footer-right">{{ $numero }}</td>
    </tr>
  </table>
</div>

</body>
</html>
