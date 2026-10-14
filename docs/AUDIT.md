# Audit qualité — sylius-trade-credit-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par lecture directe de l'orchestrateur.

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 54 tests, 1710 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 43 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 packages abandonnés (transitifs) |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 43 fichiers signalés relèvent uniquement de `RemoveNullArgOnNullDefaultParamRector` (arguments `null` redondants sur paramètres à défaut `null`) — **suggestions de modernisation non bloquantes**, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

**Dépendances abandonnées** : `behat/transliterator` et `php-http/message-factory` signalés dans les dépendances transitives (non pilotées directement) ; aucune advisory de sécurité.

## 2. Audit qualitatif (revue par l'orchestrateur — lecture directe)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **9/10** | Strict types, classes `final`, value objects, écriture SQL directe pour le critique financier, `TradeCreditReserver` seul writer. |
| Intégration Symfony / Sylius | **8/10** | Workflow listeners (jamais des guards), `onFlush` pour les switchs de méthode, CSRF admin, kernel.exception pour les refus de réservation, `StateMachineInterface` bien abstrait. |
| Sécurité (OWASP 2025 — intégrité financière) | **9/10** | `SELECT FOR UPDATE` + `UPDATE atomique (check-and-debit)` + close idempotent + fail-closed ERP = architecture financière exemplaire. Injection: 100 % DBAL paramétré. |

### Points forts

- `TradeCreditReserver` : seul writer des montants committés, en SQL pur (`transactional()`), avec verrouillage de la ligne compte avant chaque opération — les réservations et libérations sont **sérialisées par compte**. L'`UPDATE account SET committed = committed + :amount WHERE ... AND (allow_exceed OR limit - committed - :external >= :amount)` est un vrai *check-and-debit atomique* : 0 lignes affectées = refus, sans jamais passer par un objet ORM périmé.
- `closeRow` : `UPDATE ... WHERE state = 'reserved'` — seul le premier appelant concurrent libère ; idempotence parfaite quelle que soit la concurrence.
- Fail-closed sur position ERP inconnue (`ExternalCreditUnavailableException` → `TradeCreditReservationException`) : jamais de réservation en mode dégradé si le fournisseur externe tombe.
- `OpenItemSettler` : triple-check avec verrou pessimiste (`LockMode::PESSIMISTIC_WRITE` + `refresh`) pour les règlements concurrents (import CSV + Mark as paid).
- `PaymentMethodSwitchListener` (Doctrine `onFlush`) : capte **tous** les changements de méthode quelle que soit la couche (shop, API, admin), garantissant qu'une libération/réservation est toujours faite.
- `declare(strict_types=1)` + classes `final` sur tous les services, value objects immuables (`PaymentTerms`, `CreditPosition`, `AgeingBuckets`, `PaymentImportResult`).
- Toutes les requêtes SQL : DBAL avec types explicites (`ParameterType::INTEGER`, `Types::DATETIME_IMMUTABLE`), aucune interpolation de valeur utilisateur.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Double flush post-settlement** (`MarkAsPaidController.php:52`, `OpenItemSettler.php:70-71`). `OpenItemSettler::settle()` appelle déjà `$entityManager->flush()` à l'intérieur de `wrapInTransaction`. Le contrôleur rappelle ensuite `$this->entityManager->flush()` (ligne 52). En pratique no-op dans la majorité des cas, mais si les listeners du `sylius_payment complete` (ex. `TradeCreditWorkflowListener`, dispatches Invoicing) créent des entités en UoW, elles sont flushées **hors** de la transaction du settler. → Retirer le `flush()` du contrôleur ou confier la responsabilité entière au settler.

2. **Open redirect résiduel** (`ReservationExceptionListener.php:74`). Le fallback de redirection utilise le header `Referer` brut : `$referer = $request->headers->get('referer')`. Un `Referer` forgé peut pointer vers un domaine externe, exposant un open redirect au sens OWASP A01:2025. → Valider que l'URL du referer appartient à l'hôte courant avant de l'utiliser, ou utiliser une route de fallback interne sûre (`sylius_shop_homepage`).

3. **`TradeCreditAccount` non `final`** (`Entity/TradeCreditAccount.php`). Le pattern Sylius resource est acceptable pour l'extensibilité, mais les méthodes financières critiques (`getCreditLimit`, `getCommittedAmount`, `getAvailableAmount`, `isAllowExceed`) peuvent être surchargées sans contrainte. → Documenter explicitement les contrats de surcharge, ou rendre `final` les méthodes de lecture critique (avec `@internal` sur l'entité si la surcharge complète n'est pas supportée).

4. **Requête `reservedRow` sans `LIMIT 1`** (`TradeCreditReserver.php:455`). La query retourne toutes les lignes réservées pour un order et un state ; `fetchAssociative` n'en consomme que la première. L'architecture garantit une seule réservation active par commande, mais l'intention n'est pas explicite dans le SQL. → Ajouter `LIMIT 1` pour clarifier l'invariant et éviter un `ORDER BY` complet.

### 🟡 Mineur

- `TradeCreditReserver.php:11` (constructeur à 11 paramètres) : regrouper les collaborateurs de résolution du crédit (`positionResolver`, `termsRepository`, `policyProvider`) dans un `CreditResolutionContext` allègerait le constructeur sans changer la logique.
- Rector — `RemoveNullArgOnNullDefaultParamRector` : 43 fichiers concernés. Appliquer `make rector-fix` en suivant d'un passage ECS/PHPStan/tests avant la prochaine release.
- 2 packages transitifs abandonnés (`behat/transliterator`, `php-http/message-factory`) : surveiller les montées de version de Sylius/Behat qui les remplaceront.
- `closeRow` soustrait `committed_amount - :amount` sans plancher à 0 : l'invariant est garanti par le lock et la logique applicative, mais un commentaire explicitant pourquoi pas de `MAX(committed_amount - :amount, 0)` renforcerait la maintenabilité.

### ℹ️ Pour information (choix de conception assumés)

- `refreshEntities = false` passé par `PaymentMethodSwitchListener` (appel pendant un flush Doctrine) : correct — aucun refresh pendant une flush active.
- `termsOf()` préserve la date de début de la première réservation placée (non-stale) : intentionnel pour que les termes d'une commande ne recommencent jamais.
- `mismatchedReservations` laisse les réservations dont l'ERP est indisponible intactes (`continue`) jusqu'au prochain run de la commande : fail-safe documenté.

## 4. Verdict

Plugin **financièrement solide** : l'architecture de réservation atomique avec verrous SQL (`SELECT FOR UPDATE` + `UPDATE check-and-debit`) est le point fort majeur — aucune race condition possible sur le débit d'encours. Outillage au vert (ECS, PHPStan level max, 54 tests unitaires / 1710 assertions), strict types, value objects immuables, fail-closed sur l'ERP. Les axes d'amélioration prioritaires sont le **double flush** dans `MarkAsPaidController` (risque de flush hors transaction) et l'**open redirect résiduel** sur le header `Referer` — deux points à corriger avant release production. Rector (43 fichiers, suggestions mineures) peut être appliqué en une passe.
