{{-- resources/views/pdf/facture.blade.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture {{ $facture->numero }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a2e;
            background: #fff;
            line-height: 1.4;
        }

        .top-bar { background: #0f3460; height: 5px; width: 100%; }

        .page-wrap { padding: 16px 28px 72px 28px; }

        /* ── Header ── */
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .header-logo-cell { width: 180px; vertical-align: middle; }
        .header-logo-cell img { width: 150px; max-height: 48px; }
        .header-logo-cell .brand-fallback {
            font-size: 15px; font-weight: bold; color: #0f3460; letter-spacing: 1px;
        }
        .header-company-cell {
            vertical-align: middle; text-align: right;
            font-size: 9px; color: #444; line-height: 1.55;
        }
        .header-company-cell .company-name {
            font-size: 11px; font-weight: bold; color: #0f3460;
            display: block; margin-bottom: 1px;
        }

        .divider { border: none; border-top: 2px solid #0f3460; margin-bottom: 12px; }

        /* ── Titre ── */
        .invoice-title-row { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .invoice-title { font-size: 18px; font-weight: bold; color: #0f3460; letter-spacing: .4px; }
        .invoice-numero { font-size: 11px; color: #555; margin-top: 2px; }
        .status-badge {
            display: inline-block; padding: 4px 10px; border-radius: 3px;
            font-size: 10px; font-weight: bold; letter-spacing: .4px;
        }
        .status-paid     { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-partial  { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .status-unpaid   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .status-draft    { background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db; }

        /* ── Blocs info ── */
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .info-box {
            width: 50%; vertical-align: top;
            padding: 9px 12px;
            border: 1px solid #dde3ed; background: #f7f9fc;
        }
        .info-box-right { border-left: none; }
        .info-box-label {
            font-size: 8.5px; font-weight: bold; color: #0f3460;
            text-transform: uppercase; letter-spacing: .8px;
            margin-bottom: 5px; padding-bottom: 4px;
            border-bottom: 1px solid #dde3ed;
        }
        .info-line { margin-bottom: 2px; color: #333; }
        .info-line strong { color: #0f3460; }

        /* ── Table principale ── */
        .main-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .main-table thead tr { background: #0f3460; color: #fff; }
        .main-table thead th {
            padding: 6px 8px; text-align: left;
            font-size: 9px; font-weight: bold; letter-spacing: .3px;
        }
        .main-table thead th.right { text-align: right; }
        .main-table thead th.center { text-align: center; }
        .main-table tbody tr { border-bottom: 1px solid #e8edf5; }
        .main-table tbody tr:nth-child(even) { background: #f7f9fc; }
        .main-table tbody td {
            padding: 6px 8px; vertical-align: top; color: #333;
        }
        .main-table tbody td.right { text-align: right; }
        .main-table tbody td.center { text-align: center; }
        .main-table tbody td.bold { font-weight: bold; }

        /* ── Participants ── */
        .section-title {
            font-size: 9px; font-weight: bold; color: #0f3460;
            text-transform: uppercase; letter-spacing: .8px;
            margin: 10px 0 5px 0; padding-bottom: 4px;
            border-bottom: 1px solid #0f3460;
        }
        .part-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .part-table thead tr { background: #e8edf5; }
        .part-table thead th {
            padding: 5px 7px; text-align: left;
            font-size: 9px; font-weight: bold; color: #0f3460;
            border-bottom: 1px solid #c8d5e8;
        }
        .part-table tbody tr { border-bottom: 1px solid #eef1f6; }
        .part-table tbody td { padding: 4px 7px; font-size: 9px; color: #444; }
        .role-badge {
            display: inline-block; padding: 1px 5px;
            border-radius: 2px; font-size: 8px; font-weight: bold;
        }
        .role-adult    { background: #d1ecf1; color: #0c5460; }
        .role-child    { background: #fff3cd; color: #856404; }
        .role-passenger { background: #e2e3e5; color: #383d41; }
        .role-default  { background: #e8edf5; color: #0f3460; }

        /* ── Totaux ── */
        .totals-wrap { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .totals-spacer { width: 55%; }
        .totals-box { width: 45%; vertical-align: top; }
        .totals-table { width: 100%; border-collapse: collapse; border: 1px solid #dde3ed; }
        .totals-table td { padding: 5px 10px; border-bottom: 1px solid #eef1f6; font-size: 10px; }
        .totals-table .label { color: #555; }
        .totals-table .value { text-align: right; color: #222; font-weight: bold; }
        .totals-table .total-row { background: #0f3460; }
        .totals-table .total-row td { color: #fff; border-bottom: none; }
        .totals-table .paid-row { background: #d4edda; }
        .totals-table .paid-row td { color: #155724; }
        .totals-table .due-row { background: #fff3cd; }
        .totals-table .due-row td { color: #856404; }

        /* ── Mention légale ── */
        .legal-note { margin-top: 10px; font-size: 8.5px; color: #888; text-align: center; font-style: italic; }

        /* ── Signature ── */
        .signature-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .signature-cell { width: 40%; text-align: right; vertical-align: bottom; }
        .signature-box {
            display: inline-block; border-top: 1px solid #aaa;
            padding-top: 5px; font-size: 9px; color: #555; min-width: 130px;
        }

        /* ── Footer fixe ── */
        .footer {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #0f3460; color: #fff; font-size: 8.5px; padding: 7px 28px;
            line-height: 1.6;
        }
        .footer-inner { width: 100%; border-collapse: collapse; }
        .footer-left  { color: #b8c9e4; }
        .footer-right { text-align: right; color: #b8c9e4; }
        .footer-center { text-align: center; color: #fff; font-weight: bold; }
    </style>
</head>
<body>

@php
    $reservation  = $facture->reservation ?? null;
    $client       = $reservation?->client ?? null;
    $participants = $reservation?->participants ?? collect();

    $pay          = $pay ?? null;
    $percentPaid  = (int) ($pay['percent'] ?? 0);

    $total     = (float) ($facture->montant_total ?? $facture->montant_ttc ?? $facture->total ?? 0);
    $sousTotal = (float) ($facture->montant_sous_total ?? $facture->montant_ht ?? $total);
    $taxes     = (float) ($facture->montant_taxes ?? $facture->montant_tva ?? 0);
    $paid      = (float) ($pay['paid'] ?? 0);
    $remaining = (float) ($pay['remaining'] ?? 0);

    $statut = strtolower((string) ($facture->statut ?? ''));
    $statusClass = match($statut) {
        'payee', 'paye'                  => 'status-paid',
        'partielle','paye_partiellement' => 'status-partial',
        'impayee', 'emis'               => 'status-unpaid',
        default                          => 'status-draft',
    };
    $statusLabel = match($statut) {
        'payee', 'paye'                  => 'PAYÉE',
        'partielle','paye_partiellement' => 'PARTIELLE',
        'impayee'                        => 'IMPAYÉE',
        'emis'                           => 'ÉMISE',
        'brouillon'                      => 'BROUILLON',
        'annule'                         => 'ANNULÉE',
        default                          => strtoupper($statut),
    };

    $reservationRef  = $reservation?->reference ?? '—';
    $reservationType = $reservation?->type_label ?? ($reservation?->type ?? '—');
    $produitNom      = $reservation?->produit?->nom ?? null;
    $forfaitNom      = $reservation?->forfait?->nom ?? null;
    $description     = implode(' · ', array_filter([$reservationType, $produitNom, $forfaitNom]));

    $dateFacture = $facture->date_facture
        ? \Carbon\Carbon::parse($facture->date_facture)->format('d/m/Y')
        : date('d/m/Y');

    $clientName = $client
        ? trim(($client->prenom ?? '') . ' ' . ($client->nom ?? ''))
        : '—';

    $hasParticipants = $participants->isNotEmpty();

    function roleBadgeClass(string $role): string {
        return match(strtolower($role)) {
            'adult', 'adulte'                   => 'role-adult',
            'child', 'enfant'                   => 'role-child',
            'passenger', 'passager', 'demandeur', 'beneficiary', 'beneficiaire' => 'role-passenger',
            default                             => 'role-default',
        };
    }
    function roleLabel(string $role): string {
        return match(strtolower($role)) {
            'adult', 'adulte'       => 'Adulte',
            'child', 'enfant'       => 'Enfant',
            'passenger', 'passager' => 'Passager',
            'demandeur'             => 'Demandeur',
            'beneficiary', 'beneficiaire' => 'Bénéficiaire',
            default                 => ucfirst($role) ?: 'Participant',
        };
    }
@endphp

<div class="top-bar"></div>

<div class="page-wrap">

    {{-- ── HEADER ── --}}
    <table class="header-table">
        <tr>
            <td class="header-logo-cell">
                @if($logoPath && file_exists($logoPath))
                    <img src="{{ $logoPath }}" alt="Universal Tours">
                @else
                    <div class="brand-fallback">UNIVERSAL TOURS</div>
                @endif
            </td>
            <td class="header-company-cell">
                <span class="company-name">UNIVERSAL TOURS SARL</span>
                Villa n°D40, Cité BCEAO – Nord Foire – Dakar, Sénégal<br>
                NINEA : 010321503 &nbsp;|&nbsp; RC : SN.DKR.2023 B.22726<br>
                Tél : +221 77 579 96 01 &nbsp;|&nbsp; universaltoursn@gmail.com<br>
                Rib : SN04801007119035000143 – Banque Agricole
            </td>
        </tr>
    </table>

    <hr class="divider">

    {{-- ── TITRE + STATUT ── --}}
    <table class="invoice-title-row">
        <tr>
            <td>
                <div class="invoice-title">FACTURE</div>
                <div class="invoice-numero">N° {{ $facture->numero }}</div>
            </td>
            <td style="text-align:right; vertical-align:middle;">
                <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
            </td>
        </tr>
    </table>

    {{-- ── INFOS FACTURE + CLIENT ── --}}
    <table class="info-table">
        <tr>
            <td class="info-box">
                <div class="info-box-label">Informations facture</div>
                <div class="info-line"><strong>Date :</strong> {{ $dateFacture }}</div>
                <div class="info-line"><strong>Échéance :</strong> Règlement immédiat</div>
                <div class="info-line"><strong>Référence :</strong> {{ $reservationRef }}</div>
                @if($reservationType !== '—')
                    <div class="info-line"><strong>Type :</strong> {{ $reservationType }}</div>
                @endif
                @if($forfaitNom)
                    <div class="info-line"><strong>Forfait :</strong> {{ $forfaitNom }}</div>
                @endif
            </td>
            <td class="info-box info-box-right">
                <div class="info-box-label">Client</div>
                @if($client)
                    <div class="info-line" style="font-weight:bold; font-size:11px;">{{ $clientName }}</div>
                    @if($client->adresse)
                        <div class="info-line">{{ $client->adresse }}</div>
                    @endif
                    @if($client->pays)
                        <div class="info-line">{{ $client->pays }}</div>
                    @endif
                    @if($client->telephone)
                        <div class="info-line"><strong>Tél :</strong> {{ $client->telephone }}</div>
                    @endif
                    @if($client->email)
                        <div class="info-line"><strong>Email :</strong> {{ $client->email }}</div>
                    @endif
                @else
                    <div class="info-line">—</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── LIGNES DE PRESTATION ── --}}
    <div class="section-title">Détail de la prestation</div>
    <table class="main-table">
        <thead>
            <tr>
                <th style="width:18%">Référence</th>
                <th>Description</th>
                <th class="right" style="width:18%">Prix unitaire</th>
                <th class="center" style="width:8%">Qté</th>
                <th class="right" style="width:18%">Total HT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="bold">{{ $reservationRef }}</td>
                <td>
                    {{ $description ?: $reservationType }}
                    @if($hasParticipants)
                        <br><span style="font-size:8.5px; color:#777;">{{ $participants->count() }} participant(s)</span>
                    @endif
                </td>
                <td class="right">{{ number_format($sousTotal, 0, ',', ' ') }} FCFA</td>
                <td class="center">1</td>
                <td class="right bold">{{ number_format($sousTotal, 0, ',', ' ') }} FCFA</td>
            </tr>
        </tbody>
    </table>

    {{-- ── PARTICIPANTS ── --}}
    @if($hasParticipants)
    <div class="section-title">Participants / Passagers</div>
    <table class="part-table">
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th style="width:25%">Nom</th>
                <th style="width:22%">Prénom</th>
                <th style="width:8%">Âge</th>
                <th style="width:20%">Passeport</th>
                <th style="width:12%">Rôle</th>
                <th>Remarques</th>
            </tr>
        </thead>
        <tbody>
            @foreach($participants as $i => $p)
            <tr>
                <td style="color:#999;">{{ $i + 1 }}</td>
                <td>{{ $p->nom ?? '—' }}</td>
                <td>{{ $p->prenom ?? '—' }}</td>
                <td>{{ $p->age ? $p->age . ' ans' : '—' }}</td>
                <td style="font-family: monospace; font-size:8.5px;">{{ $p->passport ?? '—' }}</td>
                <td>
                    <span class="role-badge {{ roleBadgeClass($p->role ?? '') }}">
                        {{ roleLabel($p->role ?? '') }}
                    </span>
                </td>
                <td style="color:#666; font-size:8.5px;">{{ $p->remarques ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ── TOTAUX ── --}}
    <table class="totals-wrap">
        <tr>
            <td class="totals-spacer"></td>
            <td class="totals-box">
                <table class="totals-table">
                    <tr>
                        <td class="label">Sous-total HT</td>
                        <td class="value">{{ number_format($sousTotal, 0, ',', ' ') }} FCFA</td>
                    </tr>
                    <tr>
                        <td class="label">TVA</td>
                        <td class="value">{{ number_format($taxes, 0, ',', ' ') }} FCFA</td>
                    </tr>
                    <tr class="total-row">
                        <td class="label" style="font-weight:bold; font-size:11px;">Total TTC</td>
                        <td class="value" style="font-size:11px;">{{ number_format($total, 0, ',', ' ') }} FCFA</td>
                    </tr>
                    @if($pay && $paid > 0)
                    <tr class="paid-row">
                        <td class="label">Montant versé ({{ $percentPaid }}%)</td>
                        <td class="value">{{ number_format($paid, 0, ',', ' ') }} FCFA</td>
                    </tr>
                    @endif
                    @if($pay && $remaining > 0)
                    <tr class="due-row">
                        <td class="label" style="font-weight:bold;">Reste à régler</td>
                        <td class="value" style="font-weight:bold;">{{ number_format($remaining, 0, ',', ' ') }} FCFA</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    {{-- ── MODES DE PAIEMENT (uniquement si reste à payer) ── --}}
    @if(($pay && $remaining > 0) || !$pay)
    <table style="width:100%; border-collapse:collapse; margin-top:10px; margin-bottom:8px;">
        <tr>
            <td style="border:1px solid #dde3ed; background:#f7f9fc; padding:9px 12px; border-radius:3px;">
                <div style="font-size:9.5px; font-weight:bold; color:#0f3460; margin-bottom:5px; letter-spacing:.3px;">
                    MODES DE PAIEMENT ACCEPTÉS
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:9px; color:#444;">
                    <tr>
                        <td style="width:50%; padding:2px 0;">
                            <strong>📱 Wave / Orange Money</strong> : +221 77 579 96 01
                        </td>
                        <td style="width:50%; padding:2px 0;">
                            <strong>🏦 Virement</strong> : SN04801007119035000143 (Banque Agricole)
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:2px 0;">
                            <strong>💵 Espèces</strong> : au bureau (Cité BCEAO, Nord Foire)
                        </td>
                        <td style="padding:2px 0;">
                            <strong>💬 WhatsApp</strong> : +221 77 579 96 01 (suivi paiement)
                        </td>
                    </tr>
                </table>
                <div style="margin-top:6px; padding-top:5px; border-top:1px dashed #cbd5e0; font-size:8.5px; color:#666;">
                    Merci de mentionner la référence <strong>{{ $facture->numero }}</strong> lors de tout paiement.
                </div>
            </td>
        </tr>
    </table>
    @endif

    {{-- ── SIGNATURE ── --}}
    <table class="signature-table">
        <tr>
            <td style="vertical-align:bottom; font-size:9px; color:#666;">
                @if($taxes == 0)
                    <em>Exonéré de TVA – Art. 395 CGI Sénégal</em>
                @endif
            </td>
            <td class="signature-cell">
                <div class="signature-box">
                    La Direction<br>
                    <span style="font-size:8.5px; color:#aaa;">Cachet &amp; Signature</span>
                </div>
            </td>
        </tr>
    </table>

</div>

{{-- ── FOOTER FIXE ── --}}
<div class="footer">
    <table class="footer-inner">
        <tr>
            <td class="footer-left" style="width:34%;">
                <strong style="color:#fff;">UNIVERSAL TOURS SARL</strong><br>
                Villa n°D40, Cité BCEAO – Nord Foire – Dakar, Sénégal<br>
                NINEA : 010321503 &nbsp;|&nbsp; RC : SN.DKR.2023 B.22726
            </td>
            <td class="footer-center" style="width:34%;">
                Tél / WhatsApp : +221 77 579 96 01<br>
                universaltoursn@gmail.com
            </td>
            <td class="footer-right" style="width:32%;">
                Banque Agricole<br>
                RIB : SN04801007119035000143<br>
                {{ $facture->numero }}
            </td>
        </tr>
    </table>
</div>

</body>
</html>
