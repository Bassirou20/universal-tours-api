{{-- resources/views/pdf/devis.blade.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Devis {{ $devis['numero'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color:#000; }

        .header { width: 100%; margin-bottom: 18px; }
        .logo { width: 220px; }
        .company { font-size: 11px; line-height: 1.5; }

        .title { font-size: 22px; font-weight: bold; margin: 12px 0 6px; }
        .subtitle { font-size: 11px; margin: 0 0 12px; }

        .box { border: 1px solid #000; padding: 10px; }
        .row { display: table; width: 100%; }
        .col { display: table-cell; vertical-align: top; }

        .muted { font-size: 11px; color: #222; }
        .note {
            margin-top: 10px;
            padding: 8px 10px;
            border: 1px dashed #000;
            font-size: 11px;
            line-height: 1.45;
        }

        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border: 1px solid #000; padding: 6px; }
        th { background: #f2f2f2; text-align: center; }
        td { vertical-align: top; }
        .right { text-align: right; }
        .center { text-align: center; }

        .footer { position: fixed; bottom: 40px; left: 0; right: 0; font-size: 11px; }
        .signature { margin-top: 26px; text-align: right; }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #000;
            font-weight: bold;
            font-size: 11px;
        }

        .grid-4 { width: 100%; margin-top: 10px; }
        .kpi {
            display: inline-block;
            width: 23.5%;
            vertical-align: top;
            border: 1px solid #000;
            padding: 8px 10px;
            margin-right: 1%;
            box-sizing: border-box;
        }
        .kpi:last-child { margin-right: 0; }
        .kpi .label { font-size: 11px; }
        .kpi .value { font-size: 13px; font-weight: bold; margin-top: 3px; }
    </style>
</head>
<body>

@php
  // Inputs attendus:
  // $devis (array): numero, date, validite, echeance (optionnel)
  // $reservation (model) avec client (optionnel)
  // $montant_total (number)
  // $logoPath (optionnel) => chemin absolu/accessible
  // $pay (optionnel) => ['label','paid','remaining','percent']

  $logoPath = $logoPath ?? null;
  $pay = $pay ?? null;

  $reservation = $reservation ?? null;
  $client = $reservation?->client ?? null;

  $numero = $devis['numero'] ?? '';
  $dateDevis = $devis['date'] ?? null;
  $validite = $devis['validite'] ?? null;
  $echeance = $devis['echeance'] ?? 'Règlement immédiat';

  $reservationRef = $reservation?->reference ?? '—';
  $reservationType = $reservation?->type_label ?? ($reservation?->type ?? '—');

  $total = (float) ($montant_total ?? 0);

  $percentPaid = 0;
  if ($pay && isset($pay['percent'])) $percentPaid = (int) $pay['percent'];
@endphp

    {{-- HEADER --}}
    <div class="header row">
        <div class="col">
            @if($logoPath)
                <img src="{{ $logoPath }}" class="logo" alt="Universal Tours">
            @else
                <div style="font-weight:bold;font-size:16px;">UNIVERSAL TOURS</div>
            @endif
        </div>

        <div class="col company">
            <strong>UNIVERSAL TOURS</strong><br>
            Société à Responsabilité Limitée (SARL)<br>
            <!-- Capital : 1 000 000 FCFA<br> -->
            Siège social : Villa n°D40, Cité BCEAO – Nord Foire – Dakar – Sénégal<br>
            NINEA : 010321503 / RC : SN.DKR.2023 B.22726<br>
            Tél : 77 579 96 01 / Email : universaltoursn@gmail.com<br>
            Compte bancaire : SN04801007119035000143 / Banque Agricole
        </div>
    </div>

    {{-- TITLE --}}
    <div class="title">
        DEVIS / FACTURE PRO FORMA N° {{ $numero }}
    </div>
    <div class="subtitle muted">
        Ce document est un devis (pro forma) et ne constitue pas une facture fiscale.
    </div>

    {{-- INFOS --}}
    <div class="row box">
        <div class="col">
            <strong>Date :</strong>
            {{ $dateDevis ? \Carbon\Carbon::parse($dateDevis)->format('d/m/Y') : '—' }}<br>

            <strong>Validité :</strong>
            {{ $validite ? \Carbon\Carbon::parse($validite)->format('d/m/Y') : '—' }}<br>

            <strong>Échéance :</strong> {{ $echeance }}<br>
            <strong>Réservation :</strong> {{ $reservationRef }}

            @if($pay)
                <br><br>
                <strong>Règlement :</strong>
                <span class="badge">{{ $pay['label'] ?? '—' }}</span>
                <br>

                <strong>Payé :</strong>
                {{ number_format((float)($pay['paid'] ?? 0), 0, ',', ' ') }} FCFA
                <span class="muted">({{ $percentPaid }}%)</span>
                <br>

                <strong>Reste :</strong>
                {{ number_format((float)($pay['remaining'] ?? 0), 0, ',', ' ') }} FCFA
            @endif
        </div>

        <div class="col">
            <strong>Client :</strong><br>
            @if($client)
                {{ trim(($client->nom ?? '') . ' ' . ($client->prenom ?? '')) }}<br>
                {{ $client->adresse ?? '' }}<br>
                {{ $client->pays ?? '' }}<br>
                Tél : {{ $client->telephone ?? '' }}<br>
                Email : {{ $client->email ?? '' }}
            @else
                —
            @endif
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid-4">
        <div class="kpi">
            <div class="label muted">Total</div>
            <div class="value">{{ number_format($total, 0, ',', ' ') }} FCFA</div>
        </div>
        <div class="kpi">
            <div class="label muted">Payé</div>
            <div class="value">
                {{ number_format((float)($pay['paid'] ?? 0), 0, ',', ' ') }} FCFA
            </div>
        </div>
        <div class="kpi">
            <div class="label muted">Reste</div>
            <div class="value">
                {{ number_format((float)($pay['remaining'] ?? $total), 0, ',', ' ') }} FCFA
            </div>
        </div>
        <div class="kpi">
            <div class="label muted">% payé</div>
            <div class="value">{{ $pay ? $percentPaid : 0 }}%</div>
        </div>
    </div>

    {{-- TABLE --}}
    <table>
        <thead>
            <tr>
                {{-- ✅ “#” = Référence réservation --}}
                <th style="width:18%">#</th>
                <th>Description</th>
                <th style="width:15%">PU HT</th>
                <th style="width:10%">Qté</th>
                <th style="width:20%">Montant HT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="center" style="font-weight:bold;">{{ $reservationRef }}</td>
                <td>{{ $reservationType }}</td>
                <td class="right">{{ number_format($total, 0, ',', ' ') }}</td>
                <td class="right">1</td>
                <td class="right">{{ number_format($total, 0, ',', ' ') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- TOTALS --}}
    <table style="margin-top:10px;">
        <tr>
            <td class="right"><strong>Total HT</strong></td>
            <td class="right" style="width:20%">{{ number_format($total, 0, ',', ' ') }} FCFA</td>
        </tr>

        <tr>
            <td class="right"><strong>TVA</strong></td>
            <td class="right">0 FCFA</td>
        </tr>

        @if($pay)
            <tr>
                <td class="right"><strong>Montant payé</strong></td>
                <td class="right">
                    {{ number_format((float)($pay['paid'] ?? 0), 0, ',', ' ') }} FCFA
                    <span class="muted">({{ $percentPaid }}%)</span>
                </td>
            </tr>
            <tr>
                <td class="right"><strong>Reste à payer</strong></td>
                <td class="right"><strong>{{ number_format((float)($pay['remaining'] ?? 0), 0, ',', ' ') }} FCFA</strong></td>
            </tr>
        @endif

        <tr>
            <td class="right"><strong>Total TTC</strong></td>
            <td class="right"><strong>{{ number_format($total, 0, ',', ' ') }} FCFA</strong></td>
        </tr>
    </table>

    {{-- NOTE / CONDITIONS --}}
    <div class="note">
        <strong>Important :</strong><br>
        - Ce document est un <strong>devis / pro forma</strong> et <strong>n’a pas valeur de facture</strong>.<br>
        - Validité du devis : jusqu’au <strong>{{ $validite ? \Carbon\Carbon::parse($validite)->format('d/m/Y') : '—' }}</strong>.<br>
        - Les prestations seront confirmées après validation et, si applicable, versement d’un acompte.
    </div>

    {{-- SIGNATURE --}}
    <div class="signature">
        <strong>La Direction</strong><br>
        <!-- Contrôle : OK -->
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        Merci pour votre confiance
    </div>

</body>
</html>
