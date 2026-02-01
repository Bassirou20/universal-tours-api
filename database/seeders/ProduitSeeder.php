<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Produit;

class ProduitSeeder extends Seeder
{
    public function run(): void
    {
        $datas = [
            ['type' => 'billet_avion', 'nom' => 'Billet Dakar → Jeddah (Oumrah)', 'description' => 'Billet aller-retour', 'prix_base' => 650000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'evenement', 'nom' => 'Oumrah – Pack 10 jours', 'description' => 'Hébergement + transferts', 'prix_base' => 1500000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'evenement', 'nom' => 'Ziarrah à Fès', 'description' => 'Circuit au Maroc', 'prix_base' => 900000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'hotel', 'nom' => 'Hôtel Saly Beach – Nuitée', 'description' => 'Chambre double, petit-déj', 'prix_base' => 80000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'voiture', 'nom' => 'Location Berline – Jour', 'description' => 'Avec chauffeur', 'prix_base' => 40000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'evenement', 'nom' => 'Excursion Île de Gorée', 'description' => 'Demi-journée guidée', 'prix_base' => 25000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'evenement', 'nom' => 'Croisière bateau – 1 nuit', 'description' => 'Cabine standard', 'prix_base' => 300000, 'devise' => 'XOF', 'actif' => true],
            ['type' => 'evenement', 'nom' => 'Camp vacances enfants – Semaine', 'description' => 'Encadrement complet', 'prix_base' => 200000, 'devise' => 'XOF', 'actif' => true],
        ];

        foreach ($datas as $d) {
            Produit::create($d);
        }
    }
}
