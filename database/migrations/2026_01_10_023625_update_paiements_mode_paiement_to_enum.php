<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Normaliser les anciennes valeurs (très important avant l'ENUM)
        // Mets tout en minuscules + remplace espaces/tirets
        DB::statement("UPDATE paiements SET mode_paiement = LOWER(TRIM(mode_paiement))");

        // Regroupements courants (adapte si tu as d'autres valeurs)
        DB::statement("UPDATE paiements SET mode_paiement = 'especes' WHERE mode_paiement IN ('cash','espece','espèce','especes','espèces')");
        DB::statement("UPDATE paiements SET mode_paiement = 'carte' WHERE mode_paiement IN ('cb','carte','card')");
        DB::statement("UPDATE paiements SET mode_paiement = 'virement' WHERE mode_paiement IN ('virement','bank','banque','transfer')");
        DB::statement("UPDATE paiements SET mode_paiement = 'cheque' WHERE mode_paiement IN ('cheque','chèque')");

        DB::statement("UPDATE paiements SET mode_paiement = 'wave' WHERE mode_paiement IN ('wave','wave money')");
        DB::statement("UPDATE paiements SET mode_paiement = 'orange_money' WHERE mode_paiement IN ('om','orange','orange money','orangemoney')");
        DB::statement("UPDATE paiements SET mode_paiement = 'free_money' WHERE mode_paiement IN ('free','free money','freemoney')");

        // Si après normalisation il reste des valeurs inconnues, on les met en 'especes' (ou 'virement')
        // (Tu peux aussi choisir de les laisser et gérer manuellement)
        DB::statement("
            UPDATE paiements
            SET mode_paiement = 'especes'
            WHERE mode_paiement NOT IN ('especes','carte','virement','wave','orange_money','free_money','cheque')
               OR mode_paiement IS NULL
               OR mode_paiement = ''
        ");

        // 2) Convertir en ENUM strict
        DB::statement("
            ALTER TABLE paiements
            MODIFY mode_paiement ENUM('especes','carte','virement','wave','orange_money','free_money','cheque')
            NOT NULL
        ");
    }

    public function down(): void
    {
        // Revenir à string (moins strict)
        DB::statement("
            ALTER TABLE paiements
            MODIFY mode_paiement VARCHAR(50) NOT NULL
        ");
    }
};
