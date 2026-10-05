# SmartStock 2.1 — stock partagé entre déclinaisons (PrestaShop 1.7.7 → 8.x)

Vendre plusieurs formats d'un même produit à partir d'**un seul stock physique**.

Exemple : un thé vert vendu en sachets de 50 g, 100 g et 500 g. Le stock réel est un sac de 1 500 g,
pas « 30 sachets de 50 g + 15 de 100 g + 3 de 500 g ». Le module gère ce stock en unité de base (g, ml, pièce...)
et recalcule en permanence la quantité vendable de chaque déclinaison.

| Stock partagé | 50 g (ratio 50) | 100 g (ratio 100) | 500 g (ratio 500) |
|---|---|---|---|
| 1 500 g | 30 | 15 | 3 |
| après vente de 2 × 50 g → 1 400 g | 28 | 14 | 2 |
| après retour d'un 500 g → 1 900 g | 38 | 19 | 3 |

## Fonctionnement

- **Ratio** : unités consommées par une vente de la déclinaison (50 pour un sachet de 50 g).
  Un ratio à `0` laisse la déclinaison sur son propre stock (coffret cadeau, échantillon...).
- **Quantité vendable** d'une déclinaison = `floor(stock partagé / ratio)`. Fonctionne aussi en négatif (commandes en rupture autorisées).
- Toute variation de quantité d'une déclinaison gérée (commande, annulation, retour, modification manuelle,
  import, webservice) est convertie en unités de base et appliquée au stock partagé, puis toutes les déclinaisons sont recalculées.
  Modifier à la main la quantité du 500 g de 2 à 10 revient donc à ajouter 8 × 500 g au stock partagé.
- Les modules tiers (alertes de retour en stock, marketplaces, ERP) reçoivent le hook `actionUpdateQuantity`
  pour chaque quantité recalculée, exactement comme après une mise à jour du cœur.

### Fiabilité

- Événementiel : le module écoute `actionObjectStockAvailableAddAfter`, `actionObjectStockAvailableUpdateAfter`
  et `actionUpdateQuantity`. Aucune tâche planifiée, aucun override.
- Réconciliation idempotente : le module mémorise la dernière quantité prise en compte pour chaque déclinaison (« miroir »)
  et n'applique que l'écart. Recevoir deux fois le même événement ne change rien.
- Concurrence : verrou MySQL nommé par produit + écriture conditionnelle (`compare-and-set`) des quantités.
  Une mise à jour concurrente du cœur n'est jamais écrasée : elle est comptabilisée par son propre événement.
- Une erreur pendant une commande est journalisée (Paramètres avancés > Logs) et n'interrompt jamais la validation de commande ;
  l'écart reste en attente dans les miroirs et sera absorbé à la réconciliation suivante.
- Aucune modification des tables du cœur PrestaShop : tout est stocké dans les tables `smartstock_*`.

## Ce qui distingue SmartStock

| Fonction | Détail |
|---|---|
| **Assistant de configuration** | Détecte les produits dont les déclinaisons indiquent une quantité (`50 g`, `1 kg`, `75 cl`, `1 L`, `Pack 2 x 250 g`, `Lot de 6`), devine l'unité et les ratios, active une sélection en un clic. |
| **Unités intelligentes** | Conversion automatique (`1,5 kg` → 1500 g, `75 cl` → 750 ml), affichage lisible (`1500 g` affiché `1,5 kg`). |
| **Anti-survente panier** | Le panier entier est comparé au stock partagé (PrestaShop ne vérifie que ligne par ligne). L'excédent est retiré en commençant par la ligne modifiée, puis par les petits formats, avec un message au client. Revérifié à l'entrée du tunnel de commande. |
| **Historique complet** | Chaque mouvement du stock partagé est journalisé : vente (avec lien vers la commande), annulation/retour, modification manuelle (avec l'employé), réception, perte, inventaire, import CSV. |
| **Alertes de stock bas** | Seuil par produit, email au franchissement (une seule alerte par franchissement), mise en évidence dans le tableau de bord. |
| **Front-office** | Stock restant affiché en unité lisible (« Vite, plus que 300 g en stock ! » ou « En stock : 1,5 kg »), comparateur de prix au kg / litre entre formats avec badge « Le plus avantageux » et pourcentage d'économie. |
| **Inventaire CSV** | Export de tous les stocks partagés, import d'un inventaire physique (colonnes `id_product;shared_stock;alert_threshold`). |
| **Robustesse** | Événementiel, idempotent, verrou par produit, écritures conditionnelles : testé avec 8 processus concurrents sans perte d'une unité. Aucune modification des tables du cœur, aucun override. |

## Utilisation

1. Installer le module.
2. **Catalogue > Stock partagé > Assistant** : cocher les produits proposés et cliquer sur « Activer les produits sélectionnés ».
   Ou bien : ouvrir un produit, onglet **Modules** > *Stock partagé entre déclinaisons*, cocher « Utiliser un stock partagé ».
3. Vérifier l'unité, les ratios (« Remplir à partir des noms des déclinaisons » tient compte de l'unité saisie)
   et le stock partagé réel, éventuellement un seuil d'alerte, puis enregistrer.
4. Au quotidien, depuis **Catalogue > Stock partagé** :
   - **Mouvement** : réception (`+5000`) ou perte (`-250`) ;
   - **Inventaire** : valeur absolue du stock partagé, ou import CSV ;
   - **Historique** : derniers mouvements tous produits confondus ;
   - **Tout resynchroniser** : absorbe des modifications faites en SQL direct.

## Réglages

- **Empêcher la survente entre formats dans le panier** (activé par défaut).
- **Stock restant sur la fiche produit** : jamais / seulement sous le seuil d'alerte (défaut) / toujours.
- **Comparateur de prix au kg / litre** (activé par défaut).
- **Destinataires des alertes** : adresses séparées par des virgules, adresse de la boutique par défaut.

## Multiboutique

Le stock partagé suit exactement le périmètre de `stock_available` : une valeur par boutique, ou une seule valeur par groupe
de boutiques partageant son stock. La configuration (activation, unité, ratios, seuil) est commune à toutes les boutiques.
Le back-office affiche et modifie le stock du périmètre de la boutique sélectionnée (boutique par défaut en contexte « toutes boutiques »).

## Limites connues

- La protection du panier agit sur les paniers modifiés par le client (pages panier, produit, commande). Deux clients qui valident
  simultanément le dernier sachet restent soumis à la règle de PrestaShop : la seconde commande passe et le stock devient négatif.
- Les mouvements de stock natifs (`stock_mvt`) ne concernent que la déclinaison vendue ; l'historique du module couvre le stock partagé.
- Unités entières uniquement : choisir une unité assez fine (g plutôt que kg).
- Gestion de stock avancée (entrepôts) et packs natifs non pris en charge.

## Mises à jour

- **1.x → 2.x** : `upgrade/upgrade-2.0.0.php` reprend les produits marqués `use_common_stock` et leurs `stock_deduction`,
  crée les tables du module puis supprime les colonnes ajoutées dans `product` et `product_attribute`.
  Le stock partagé initial est calculé à partir des quantités existantes : **le vérifier après la mise à jour**.
- **2.0 → 2.1** : `upgrade/upgrade-2.1.0.php` ajoute l'historique, le seuil d'alerte, les réglages et les nouveaux hooks.

## Structure

```
ps_smartstock.php                       Module : installation, hooks
classes/SmartStockSynchronizer.php      Moteur de stock partagé (toutes les opérations critiques)
classes/SmartStockRepository.php        Accès base de données
classes/SmartStockScope.php             Périmètre de stock (boutique / groupe partagé)
classes/SmartStockUnit.php              Unités : lecture des noms, conversions, affichage
classes/SmartStockCartGuard.php         Anti-survente du panier
classes/SmartStockFrontPresenter.php    Données de la fiche produit (stock restant, comparateur de prix)
classes/SmartStockAlertNotifier.php     Email de stock bas
classes/SmartStockSettings.php          Réglages
classes/SmartStockMovementReason.php    Motifs de l'historique
classes/SmartStockPoolOperation.php     Inventaire / mouvement demandé sur un stock partagé
controllers/admin/AdminSmartStockController.php   Tableau de bord, assistant, CSV, réglages, AJAX
views/templates/admin/                  Onglet produit et tableau de bord
views/templates/hook/product_info.tpl   Bloc de la fiche produit
mails/{en,fr}/                          Modèles de l'email de stock bas
views/js/admin.js, views/css/admin.css  Interface back-office
upgrade/                                Migrations 2.0.0 et 2.1.0
translations/fr.php                     Traductions françaises
```
