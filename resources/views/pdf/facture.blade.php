{{-- resources/views/pdf/facture.blade.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture {{ $facture->numero }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color:#000; }

        .header { width: 100%; margin-bottom: 20px; }
        .logo { width: 220px; }
        .company { font-size: 11px; line-height: 1.5; }

        .title { font-size: 22px; font-weight: bold; margin: 15px 0; }

        .box { border: 1px solid #000; padding: 10px; }
        .row { display: table; width: 100%; }
        .col { display: table-cell; vertical-align: top; }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #000; padding: 6px; }
        th { background: #f2f2f2; text-align: center; }
        td { vertical-align: top; }
        .right { text-align: right; }

        .footer { position: fixed; bottom: 40px; left: 0; right: 0; font-size: 11px; }
        .signature { margin-top: 30px; text-align: right; }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border: 1px solid #000;
            font-weight: bold;
            font-size: 11px;
        }
        .muted { font-size: 11px; }
    </style>
</head>
<body>

@php
  // Variables sûres
  $pay = $pay ?? null;
  $logoPath = $logoPath ?? null;

  $reservation = $facture->reservation ?? null;
  $client = $reservation?->client ?? null;

  $percentPaid = 0;
  if ($pay && isset($pay['percent'])) $percentPaid = (int) $pay['percent'];

  // Helpers simples
  $reservationRef = $reservation?->reference ?? '—';
  $reservationType = $reservation?->type_label ?? ($reservation?->type ?? '—');

  // Montant total facture (fallback)
  $total = (float) ($facture->montant_total ?? $facture->montant_ttc ?? $facture->total ?? 0);
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
        FACTURE N° {{ $facture->numero }}
    </div>

    {{-- INFOS --}}
    <div class="row box">
        <div class="col">
            <strong>Date :</strong>
            {{ $facture->date_facture ? \Carbon\Carbon::parse($facture->date_facture)->format('d/m/Y') : '—' }}<br>

            <strong>Échéance :</strong> Règlement immédiat

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

    {{-- TABLE --}}
    <table>
        <thead>
            <tr>
                {{-- ✅ Ici: # = Référence réservation --}}
                <th style="width:18%">Référence</th>
                <th>Description</th>
                <th style="width:15%">PU HT</th>
                <th style="width:10%">Qté</th>
                <th style="width:20%">Montant HT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                {{-- ✅ # => reference --}}
                <td style="text-align:center; font-weight:bold;">
                    {{ $reservationRef }}
                </td>

                {{-- ✅ Description => type (évite duplication) --}}
                <td>
                    {{ $reservationType }}
                </td>

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
