# Universal Tours – Backend Laravel 10 (API)

**Dernière version complète** avec la gestion des employés, fournisseurs, clients, réservations, factures et autres fonctionnalités.

---

## Fonctionnalités clés

1. **Gestion des clients** : CRUD complet pour les clients avec possibilité d'import/export Excel. Le champ `prenom` est obligatoire.
2. **Produits** : Gestion des produits (billet avion, hôtel, voiture, évènements) avec CRUD complet et import/export Excel.
3. **Réservations** : Création de réservations multi-personnes et multi-lignes, avec calcul automatique du montant sous-total, taxes et total.
4. **Factures & Paiements** : Création de factures avec génération PDF et possibilité d'enregistrer des paiements.
5. **Fournisseurs** : Gestion des fournisseurs pour lier un produit à un fournisseur spécifique.
6. **Authentification des employés et admin** : Utilisation de Laravel Sanctum pour sécuriser l'accès à l'API. Possibilité de réinitialiser le mot de passe des employés.
7. **SoftDelete** : Toutes les tables principales (clients, produits, réservations, fournisseurs) utilisent le SoftDelete pour permettre la restauration des données.
8. **Devise par défaut** : La devise des produits est le **CFA (XOF)**.

---

## 1) Installation rapide

1. Créez un nouveau projet Laravel 10 :
   ```bash
   composer create-project laravel/laravel universal-tours-api "10.*"
   cd universal-tours-api
   ```

2. Installez les packages requis :
   ```bash
   composer require laravel/sanctum:^3.3 maatwebsite/excel:^3.1 barryvdh/laravel-dompdf:^2.0
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate
   ```

3. Copiez le contenu de ce module dans votre projet (écrasez/merge si demandé).

4. Mettez à jour `.env` (base de données, APP_URL).

5. Exécutez les migrations et seeders :
   ```bash
   php artisan migrate
   php artisan db:seed --class=ProduitSeeder
   php artisan key:generate
   php artisan storage:link
   ```

6. API prête à l'emploi sous `/api/*`.

---

## 2) Authentification et gestion des rôles
L'application utilise **Sanctum** pour gérer les **employés** et **admin**.

- **Admin** : Accès complet aux API.
- **Employés** : Accès restreint aux actions nécessaires selon le rôle.
- Fonction de **réinitialisation de mot de passe** via email.

### Routes d'authentification
```php
Route::post('login', [EmployeeController::class, 'login']);
Route::post('password/forget', [EmployeeController::class, 'sendResetLink']);
Route::post('password/reset', [EmployeeController::class, 'resetPassword']);
```

---

## 3) Modèles et tables principaux

1. **Clients**  
   `GET/POST/PUT/DELETE /api/clients`  
   Import et export Excel.

2. **Produits (billet_avion, hotel, voiture, evenement)**  
   CRUD pour les produits.  
   Import et export via Excel.

3. **Réservations**  
   `POST /api/reservations` crée une réservation **multi-personnes** et **multi-lignes**.  
   `POST /api/reservations/<built-in function id>/confirmer` pour générer la facture.

4. **Factures & Paiements**  
   `GET /api/factures/<built-in function id>` - Télécharger ou imprimer la facture en PDF.  
   `POST /api/factures/<built-in function id>/paiements` - Enregistrer un paiement.

5. **Fournisseurs**  
   `POST/GET/PUT/DELETE /api/fournisseurs` pour gérer les fournisseurs liés aux produits.

---

## 4) SoftDelete
Toutes les tables importantes (`clients`, `produits`, `reservations`, `fournisseurs`) utilisent **SoftDelete**.

---

## 5) Authentification et réinitialisation de mot de passe
1. Utilisation de **Sanctum** pour l'authentification des employés et administrateurs.
2. **Réinitialisation du mot de passe** des employés via email.

---

## 6) Tests
```bash
php artisan test
```
