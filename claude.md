# CLAUDE.md --- ArticleGuard WP

## 1. Mission du projet

Construire une application web professionnelle appelée **ArticleGuard
WP**.

> **ArticleGuard WP** = Back-office centralisé de gestion, d'audit et de
> correction des articles WordPress.

L'application doit permettre à un utilisateur authentifié de connecter
un site WordPress, récupérer ses articles via l'API REST WordPress, les
filtrer par catégories, détecter automatiquement des problèmes
récurrents et modifier les articles directement depuis un éditeur proche
de l'interface WordPress.

Le produit doit être réellement exploitable, pas seulement une maquette.

------------------------------------------------------------------------

## 2. Stack technique obligatoire

### Backend

-   Laravel, version stable compatible avec l'environnement disponible.
-   PHP, version compatible avec la version Laravel choisie.
-   Laravel Blade.
-   Laravel Eloquent.
-   Laravel HTTP Client pour les appels à l'API WordPress.
-   Laravel validation / policies / middleware.
-   Jobs/queues Laravel si nécessaire pour les audits lourds.

### Frontend

-   Bootstrap 5.
-   JavaScript vanilla ou modules JS simples.
-   AJAX avec `fetch()` ou Axios uniquement si déjà nécessaire.
-   Bootstrap Icons ou Font Awesome si disponible.
-   CSS personnalisé léger et propre.
-   Pas de framework frontend lourd comme React/Vue pour ce projet, sauf
    nécessité absolue.

### Base de données

Utiliser MySQL/MariaDB.

### Architecture

Privilégier : - MVC Laravel propre. - Services dédiés. - Repositories
uniquement lorsqu'ils apportent une vraie valeur. - Form Requests pour
la validation. - Policies pour les autorisations. - API WordPress isolée
dans des services dédiés. - Code réutilisable et testable.

Ne pas mettre toute la logique dans les Controllers.

------------------------------------------------------------------------

# 3. Nom et identité du projet

Nom retenu :

## ArticleGuard WP

Sous-titre : **WordPress Content Audit & Management**

Alternative courte dans l'interface : **ArticleGuard**

Le nom doit apparaître dans : - login, - navbar, - sidebar, - title des
pages, - éventuellement favicon/logo textuel.

Style visuel : - professionnel, - sobre, - léger, - orienté SaaS / outil
métier, - beaucoup d'espace blanc, - gris très clair, - texte foncé, -
violet/indigo discret comme couleur principale, - vert pour les éléments
positifs, - rouge/orange uniquement pour les alertes, - éviter les
interfaces multicolores.

Ne pas reproduire exactement une marque ou une interface tierce. Les
captures fournies servent uniquement de référence fonctionnelle et
visuelle.

------------------------------------------------------------------------

# 4. Références visuelles fournies

Deux captures ont été fournies comme inspiration.

### Capture 1 --- écran d'édition

Elle montre notamment : - sidebar verticale à gauche, - breadcrumb en
haut, - zone centrale d'édition, - titre, - URL, - éditeur de contenu, -
grande zone de contenu, - colonne latérale avec les instructions /
informations de publication, - image mise en avant, - métadonnées, -
boutons d'action.

### Capture 2 --- écran de gestion/publication

Elle montre notamment : - sidebar, - filtres de recherche, - tableau
d'articles, - cases à cocher, - référence, - date, - titre, - URL, -
date de publication, - actions, - boutons de publication, - interface
dense mais lisible.

S'inspirer de cette logique pour ArticleGuard WP, tout en créant une
interface cohérente, moderne et plus claire.

------------------------------------------------------------------------

# 5. Objectifs fonctionnels

L'application doit permettre de :

1.  Créer un compte.
2.  Se connecter.
3.  Se déconnecter.
4.  Ajouter/connecter un site WordPress.
5.  Saisir un domaine, par exemple : `https://bijouteries.top`
6.  Tester la connexion au site WordPress.
7.  Récupérer les catégories.
8.  Récupérer tous les articles.
9.  Afficher les articles dans un tableau.
10. Filtrer les articles par une ou plusieurs catégories.
11. Rechercher un article.
12. Détecter automatiquement les problèmes.
13. Afficher les remarques directement dans le tableau.
14. Afficher un statut `À corriger` lorsqu'un problème existe.
15. Ouvrir un éditeur d'article.
16. Modifier titre, contenu, URL/slug si autorisé, catégorie et image
    mise en avant.
17. Modifier les images présentes dans le contenu.
18. Sauvegarder les modifications vers WordPress via API.
19. Relancer l'audit après modification.
20. Afficher `Corrigé` ou supprimer le statut lorsque les problèmes sont
    résolus.

------------------------------------------------------------------------

# 6. Authentification de l'application

Créer une authentification simple :

-   Sign up
-   Login
-   Logout
-   Mot de passe hashé
-   Session sécurisée
-   Validation des formulaires
-   Messages d'erreur propres

Pages : - `/login` - `/register` - `/dashboard`

Toutes les pages métier doivent être protégées par authentification.

Utiliser les mécanismes standards Laravel.

------------------------------------------------------------------------

# 7. Gestion des sites WordPress

Créer une page :

`/sites`

Elle permet de gérer les sites connectés.

## Champs

-   Nom du site
-   Domaine / URL
-   Identifiant WordPress
-   Application Password WordPress
-   Statut de connexion
-   Dernière synchronisation
-   Actions : Tester / Synchroniser / Modifier / Supprimer

### Important

L'édition d'articles nécessite une authentification WordPress.

Prévoir une connexion basée sur l'API REST WordPress avec : - URL du
site - utilisateur WordPress - Application Password WordPress

Ne jamais stocker les mots de passe WordPress en clair.

Utiliser un stockage chiffré Laravel pour les secrets.

Ne jamais afficher l'Application Password complète dans l'interface
après sauvegarde.

------------------------------------------------------------------------

# 8. Connexion WordPress

Utiliser principalement :

-   `/wp-json/wp/v2/posts`
-   `/wp-json/wp/v2/categories`
-   `/wp-json/wp/v2/media`

Prévoir la gestion de pagination WordPress.

WordPress retourne notamment : - `X-WP-Total` - `X-WP-TotalPages`

Ne pas supposer qu'une seule requête récupère tous les articles.

Créer un service dédié par exemple :

`WordPressApiService`

Responsabilités : - test de connexion, - récupération des catégories, -
récupération des articles, - récupération d'un article, - mise à jour
d'un article, - récupération/upload de média, - gestion pagination, -
gestion erreurs HTTP, - timeout, - retry raisonnable.

------------------------------------------------------------------------

# 9. Dashboard

Créer un dashboard professionnel.

Afficher par exemple :

### Cartes statistiques

-   Nombre total d'articles
-   Articles sans erreur
-   Articles à corriger
-   Nombre total de problèmes
-   Nombre de catégories
-   Dernière synchronisation

### Graphiques simples

Un petit graphique peut afficher : - articles OK, - articles à
corriger, - répartition des problèmes.

Ne pas surcharger le dashboard.

------------------------------------------------------------------------

# 10. Écran principal de gestion des articles

Route suggérée :

`/articles`

En haut :

### Sélecteur de site

Exemple : `bijouteries.top`

Une fois le site sélectionné, charger ses données.

------------------------------------------------------------------------

# 11. Filtres par catégories

Afficher les catégories WordPress sous forme de filtres.

Exemple :

-   Toutes
-   Bagues
-   Bijoux
-   Bracelets
-   Colliers
-   Médailles

Permettre la sélection multiple avec des cases à cocher.

Exemple :

\[x\] Bagues \[x\] Colliers

Afficher alors les articles correspondant aux catégories sélectionnées.

Par défaut, utiliser une logique **OR** : un article appartenant à
Bagues OU Colliers est affiché.

Prévoir éventuellement un petit contrôle : -
`Correspond à au moins une catégorie` -
`Correspond à toutes les catégories`

Les filtres doivent fonctionner en AJAX sans rechargement complet de la
page.

------------------------------------------------------------------------

# 12. Tableau des articles

Colonnes principales :

1.  Checkbox
2.  Titre
3.  Catégorie(s)
4.  URL
5.  Remarques
6.  Statut
7.  Dernière analyse
8.  Actions

Exemple :

  -------------------------------------------------------------------------------
  Titre        Catégorie   URL                Remarque    Statut      Action
  ------------ ----------- ------------------ ----------- ----------- -----------
  Guide des    Bagues      /guide-bagues      Image floue À corriger  Éditer
  bagues...                                   ; 2 H1                  

  Choisir un   Colliers    /choisir-collier                           Éditer
  collier...                                                          
  -------------------------------------------------------------------------------

Si aucune erreur : - colonne Remarque vide - colonne Statut vide

Si au moins une erreur : - afficher les remarques - afficher le badge
`À corriger`

Lorsque tous les problèmes sont résolus : - remarques vides - statut
`Corrigé` possible pour conserver une indication visuelle de résolution.

------------------------------------------------------------------------

# 13. Remarques de détection

Les remarques doivent être lisibles et courtes.

Exemples :

-   `Image à la une manquante`
-   `Image dans le contenu manquante`
-   `Image potentiellement floue`
-   `Image potentiellement incohérente avec le contenu`
-   `Shortcode détecté`
-   `Titre trop long`
-   `2 balises H1 détectées`

Si plusieurs problèmes existent :

`Image à la une manquante · Titre trop long · 2 balises H1 détectées`

Prévoir des badges individuels si l'espace le permet.

------------------------------------------------------------------------

# 14. Détection 1 --- image mise en avant manquante

Pour chaque article :

-   vérifier `featured_media`.
-   Si `featured_media === 0`, signaler : `Image à la une manquante`.

Sinon : - récupérer les informations du média. - vérifier que l'image
est accessible.

------------------------------------------------------------------------

# 15. Détection 2 --- image dans le contenu manquante

Analyser le HTML du contenu.

Déterminer si le contenu contient au moins une image :

`<img ...>`

Si aucune image n'est présente : `Image dans le contenu manquante`

Important : ne pas confondre image mise en avant et image dans le corps
de l'article.

------------------------------------------------------------------------

# 16. Détection 3 --- images potentiellement floues

Créer un service :

`ImageQualityAnalyzer`

Pour chaque image accessible :

1.  télécharger l'image avec un timeout raisonnable ;
2.  vérifier les dimensions ;
3.  calculer un indicateur de netteté.

Pour la détection de flou, utiliser si possible la **variance du
Laplacien** avec une bibliothèque PHP compatible ou un service Python
séparé uniquement si réellement nécessaire.

Ne pas utiliser un seuil arbitraire sans possibilité de configuration.

Créer un seuil configurable, par exemple :

`blur_threshold`

Le résultat doit être présenté comme une détection heuristique :

`Image potentiellement floue`

et non comme une certitude absolue.

Éviter de ralentir énormément le chargement du tableau : - ne pas
analyser toutes les images à chaque affichage ; - utiliser jobs/queues
ou cache ; - enregistrer le résultat de l'analyse ; - réanalyser
uniquement si l'URL ou le média change.

------------------------------------------------------------------------

# 17. Détection 4 --- images IA / incohérentes

Cette fonctionnalité doit être conçue avec prudence.

Il est impossible de déterminer de façon fiable qu'une image est générée
par IA uniquement avec son URL.

Le besoin métier est surtout de détecter une image **potentiellement
incohérente avec le sujet de l'article**.

Créer une abstraction :

`ImageRelevanceAnalyzerInterface`

Possibilité d'implémentations : - analyse heuristique locale ; -
fournisseur de vision AI optionnel.

L'analyse peut comparer : - titre de l'article, - extrait, - texte
autour de l'image, - image.

Retour possible : - `relevant` - `possibly_incoherent` - `unknown`

Dans le tableau, afficher seulement :

`Image potentiellement incohérente`

si le score dépasse un seuil configurable.

Ne pas présenter l'analyse comme une preuve que l'image est générée par
IA.

Prévoir la possibilité de désactiver cette analyse si aucun fournisseur
AI n'est configuré.

------------------------------------------------------------------------

# 18. Détection 5 --- shortcodes

Analyser le contenu WordPress.

Détecter les shortcodes du type :

`[shortcode]`

`[shortcode attribut="valeur"]`

`[shortcode]contenu[/shortcode]`

Utiliser une expression régulière robuste ou un parseur adapté.

Si un shortcode est détecté :

`Shortcode détecté`

Idéalement afficher aussi le shortcode dans les détails de l'audit.

------------------------------------------------------------------------

# 19. Détection 6 --- titre trop long

Analyser le titre WordPress.

Créer un seuil configurable.

Valeur initiale recommandée :

`60 caractères`

Si :

`strlen(strip_tags(title)) > 60`

alors :

`Titre trop long`

Ne pas supprimer ou modifier automatiquement le titre.

L'objectif est uniquement de signaler le problème pour permettre une
correction manuelle.

------------------------------------------------------------------------

# 20. Détection 7 --- balises H1

Analyser le HTML du contenu.

Compter les :

`<h1>`

Règle :

-   0 H1 : signaler éventuellement `H1 manquant` si cette règle est
    activée ;

-   1 H1 : OK ;

-   2 H1 : signaler par exemple : `2 balises H1 détectées`.

Le besoin obligatoire est de détecter le cas où il existe plusieurs H1.

Ne pas compter le titre WordPress comme un H1 du contenu : le titre
WordPress est généralement rendu séparément par le thème.

`<h2>`

Règle :

-   0 H2 : signaler éventuellement `H2 manquant` si cette règle est
    activée ;

------------------------------------------------------------------------

# 21. Architecture du moteur d'audit

Créer une architecture modulaire.

Exemple :

``` text
AuditService
 ├── FeaturedImageRule
 ├── BodyImageRule
 ├── ImageBlurRule
 ├── ImageRelevanceRule
 ├── ShortcodeRule
 ├── LongTitleRule
 └── H1Rule
```

Chaque règle doit retourner une structure similaire :

``` php
[
    'type' => 'long_title',
    'severity' => 'warning',
    'message' => 'Titre trop long',
    'metadata' => [...]
]
```

Le moteur agrège toutes les erreurs.

Cela permettra d'ajouter facilement d'autres règles plus tard.

------------------------------------------------------------------------

# 22. Stockage des audits

Ne pas recalculer tous les audits à chaque clic.

Créer des tables appropriées, par exemple :

-   `wordpress_sites`
-   `wordpress_articles`
-   `article_audits`
-   `article_audit_issues`

Possibilité de stocker :

### wordpress_sites

-   id
-   user_id
-   name
-   url
-   wp_username
-   encrypted_application_password
-   connection_status
-   last_sync_at

### wordpress_articles

-   id
-   site_id
-   wp_id
-   title
-   slug
-   url
-   content
-   excerpt
-   featured_media_id
-   status
-   wordpress_modified_at
-   synced_at

### article_audit_issues

-   id
-   article_id
-   rule_type
-   message
-   severity
-   metadata JSON
-   detected_at
-   resolved_at

Adapter la structure si une meilleure architecture est nécessaire.

Ne jamais considérer la base locale comme la source définitive du
contenu WordPress : WordPress reste la source distante.

------------------------------------------------------------------------

# 23. Synchronisation

Prévoir :

### Synchronisation manuelle

Bouton : `Synchroniser`

### Synchronisation d'un article

Lorsqu'un article est ouvert : - récupérer les données WordPress à jour
si nécessaire.

### Après modification

Après `Mettre à jour` : 1. envoyer les modifications à WordPress ; 2.
vérifier la réponse ; 3. mettre à jour la copie locale ; 4. relancer
l'audit ; 5. afficher les nouvelles remarques.

------------------------------------------------------------------------

# 24. Éditeur d'article

Route suggérée :

`/articles/{id}/edit`

L'écran doit rappeler l'interface WordPress tout en restant cohérent
avec ArticleGuard.

### Partie principale

-   Titre
-   URL / slug
-   Éditeur visuel
-   Code source HTML
-   Catégories
-   Image mise en avant
-   Images du contenu
-   bouton `Mettre à jour`

### Éditeur

Créer deux onglets :

-   `Visuel`
-   `Code source`

L'onglet Code source doit permettre de modifier le HTML directement.

Pour l'éditeur visuel, utiliser une solution légère compatible
Bootstrap, ou un éditeur déjà intégré au projet si elle est stable.

Éviter une dépendance énorme.

------------------------------------------------------------------------

# 25. Modification des images

L'éditeur doit permettre :

### Image mise en avant

-   voir l'image actuelle ;
-   remplacer l'image ;
-   sélectionner une image existante ;
-   éventuellement uploader une nouvelle image vers WordPress Media.

### Images dans le contenu

-   détecter les `<img>` du contenu ;
-   afficher une liste des images ;
-   voir URL / alt ;
-   remplacer une image ;
-   modifier l'alt si prévu ;
-   supprimer/remplacer l'image dans le HTML.

Toute modification doit être envoyée proprement à WordPress.

------------------------------------------------------------------------

# 26. Catégories

Afficher les catégories WordPress de l'article.

Permettre : - sélectionner une ou plusieurs catégories ; - retirer une
catégorie ; - conserver les catégories existantes.

Lors de la mise à jour : envoyer les IDs des catégories à l'API
WordPress.

------------------------------------------------------------------------

# 27. Mise à jour WordPress

Utiliser :

`POST /wp-json/wp/v2/posts/{id}`

pour mettre à jour l'article.

Selon le contenu : - `title` - `content` - `slug` - `categories` -
`featured_media` - `status` si nécessaire.

Ne jamais envoyer des champs inutiles.

Gérer : - 200/201 : succès ; - 400 : données invalides ; - 401/403 :
problème d'authentification ou permission ; - 404 : article/site
introuvable ; - 429 : limitation ; - 5xx : erreur serveur WordPress.

Afficher des messages utilisateur compréhensibles.

------------------------------------------------------------------------

# 28. AJAX

Les interactions suivantes doivent éviter les rechargements inutiles :

-   filtre catégorie ;
-   recherche ;
-   changement de site ;
-   lancement d'un audit ;
-   synchronisation ;
-   sauvegarde ;
-   suppression d'une erreur locale si nécessaire ;
-   pagination ;
-   changement de statut.

Afficher : - spinner ; - skeleton ; - bouton disabled pendant l'action
; - toast de succès/erreur.

Ne jamais donner l'impression que l'application est bloquée.

------------------------------------------------------------------------

# 29. Recherche

Prévoir une recherche globale dans les articles :

-   titre ;
-   slug ;
-   URL ;
-   ID WordPress.

Recherche avec debounce JavaScript pour éviter une requête à chaque
frappe.

------------------------------------------------------------------------

# 30. Pagination

Ne pas charger plusieurs milliers d'articles dans le navigateur.

Utiliser pagination côté serveur.

Afficher : - page actuelle ; - nombre total ; - précédent ; - suivant.

La pagination doit fonctionner avec les filtres et la recherche.

------------------------------------------------------------------------

# 31. Actions en masse

Prévoir une première version avec :

-   sélectionner plusieurs articles ;
-   lancer un audit ;
-   éventuellement synchroniser.

Prévoir l'architecture pour ajouter plus tard : - modifier catégorie en
masse ; - changer statut ; - exporter les erreurs.

Ne pas développer des fonctionnalités inutiles avant que le cœur du
produit soit stable.

------------------------------------------------------------------------

# 32. Sidebar

Sidebar desktop inspirée des captures.

Sections :

### ARTICLEGUARD

-   Dashboard
-   Articles
-   Audits
-   Sites WordPress

### CONFIGURATION

-   Paramètres

En bas : - utilisateur connecté - logout

La sidebar doit être responsive.

Sur mobile : - sidebar transformée en offcanvas Bootstrap.

------------------------------------------------------------------------

# 33. Header

Header simple :

-   bouton menu mobile ;
-   breadcrumb ;
-   site WordPress actuel ;
-   utilisateur ;
-   éventuellement notification d'erreurs.

Éviter une barre supérieure trop chargée.

------------------------------------------------------------------------

# 34. Design system

Créer des variables CSS :

``` css
:root {
    --ag-primary: #6c4ce8;
    --ag-primary-dark: #5736c9;
    --ag-bg: #f7f8fb;
    --ag-surface: #ffffff;
    --ag-text: #1f2430;
    --ag-muted: #7b8190;
    --ag-border: #e8eaf0;
    --ag-success: #2e9b68;
    --ag-warning: #d98b24;
    --ag-danger: #d9534f;
}
```

La palette peut être ajustée, mais garder une interface majoritairement
neutre.

Utiliser : - border-radius modéré ; - ombres très légères ; - boutons
propres ; - badges ; - tableaux lisibles ; - espacements cohérents.

Éviter : - gradients excessifs ; - couleurs criardes ; - animations
permanentes ; - grosses ombres ; - interfaces trop chargées.

------------------------------------------------------------------------

# 35. Animations JavaScript

Les animations doivent rester discrètes.

Exemples : - fade-in du tableau ; - transition des filtres ; - skeleton
loading ; - spinner ; - toast slide-in ; - ouverture sidebar ; -
highlight temporaire après sauvegarde.

Durée recommandée : `150ms à 350ms`

Respecter `prefers-reduced-motion`.

------------------------------------------------------------------------

# 36. UX des erreurs

Les erreurs techniques ne doivent pas afficher des messages
incompréhensibles.

Exemple :

Au lieu de : `cURL error 28`

Afficher :

`Impossible de contacter le site WordPress. Vérifiez l’URL ou la disponibilité du site.`

Pour une authentification :

`Connexion WordPress refusée. Vérifiez l’utilisateur et l’Application Password.`

Conserver les détails techniques dans les logs Laravel.

------------------------------------------------------------------------

# 37. Sécurité

Obligatoire :

-   CSRF Laravel.
-   Validation stricte.
-   Authentification Laravel.
-   Authorization/policies.
-   Secrets WordPress chiffrés.
-   Protection XSS.
-   Nettoyage du HTML avant affichage lorsque nécessaire.
-   Ne jamais afficher du HTML WordPress non contrôlé sans précaution.
-   Timeout sur requêtes externes.
-   Validation des URLs.
-   Protection SSRF lors de la connexion à un domaine externe.

### SSRF

Le champ domaine est une entrée utilisateur.

Ne jamais faire confiance aveuglément à une URL fournie.

Valider : - protocole HTTP/HTTPS ; - format de domaine ; - éviter
`localhost`, `127.0.0.1`, IP privées et destinations internes ; -
empêcher les accès vers des services internes.

------------------------------------------------------------------------

# 38. Logs

Logger côté serveur : - connexion WordPress ; - synchronisation ; -
erreurs API ; - mise à jour d'article ; - erreurs d'audit.

Ne jamais logger : - Application Password ; - mots de passe ; - tokens
secrets.

------------------------------------------------------------------------

# 39. Tests

Créer des tests Laravel pour :

### Auth

-   inscription ;
-   connexion ;
-   accès protégé.

### WordPress API

Mocker les requêtes HTTP.

Tester : - récupération articles ; - pagination ; - catégories ; -
récupération média ; - mise à jour article ; - erreurs API.

### Audit

Tester séparément : - absence image à la une ; - absence image contenu
; - shortcode ; - titre \> 60 ; - plusieurs H1 ; - un seul H1 ; -
contenu sans H1 si la règle est activée ; - score de flou ; - image
potentiellement incohérente.

### Sécurité

Tester les validations principales.

------------------------------------------------------------------------

# 40. Performance

Priorités :

1.  Ne pas analyser les images à chaque affichage.
2.  Utiliser cache.
3.  Utiliser queue pour les audits lourds.
4.  Pagination.
5.  AJAX.
6.  Éviter N+1 queries.
7.  Optimiser les appels WordPress.
8.  Ne pas télécharger inutilement plusieurs fois la même image.

Pour une première version, privilégier la stabilité à une optimisation
prématurée.

------------------------------------------------------------------------

# 41. Gestion des états

Pour un article :

### Aucun problème

-   Remarque : vide
-   Statut : vide ou `OK` selon le design final.

### Problème détecté

-   Remarque : liste des problèmes
-   Statut : `À corriger`

### Problème corrigé

Après nouvel audit : - Remarque : vide - Statut : `Corrigé` si
l'historique de correction est conservé.

Ne jamais afficher `Corrigé` simplement parce que l'utilisateur a cliqué
sur sauvegarder. Le statut doit être confirmé par un nouvel audit.

------------------------------------------------------------------------

# 42. Historique

Prévoir l'architecture pour conserver : - date de dernière analyse ; -
problèmes détectés ; - problèmes résolus ; - date de correction.

La première version peut afficher uniquement : - dernière analyse ; -
état actuel.

------------------------------------------------------------------------

# 43. Structure de dossiers souhaitée

Exemple :

``` text
app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Middleware/
├── Models/
├── Services/
│   ├── WordPress/
│   │   ├── WordPressApiService.php
│   │   ├── WordPressArticleService.php
│   │   └── WordPressMediaService.php
│   └── Audit/
│       ├── AuditService.php
│       ├── Rules/
│       │   ├── FeaturedImageRule.php
│       │   ├── BodyImageRule.php
│       │   ├── ImageBlurRule.php
│       │   ├── ImageRelevanceRule.php
│       │   ├── ShortcodeRule.php
│       │   ├── LongTitleRule.php
│       │   └── H1Rule.php
│       └── ImageQualityAnalyzer.php
├── Jobs/
└── Policies/

resources/
├── views/
│   ├── layouts/
│   ├── auth/
│   ├── dashboard/
│   ├── sites/
│   ├── articles/
│   └── audits/
├── css/
└── js/

routes/
├── web.php
└── api.php
```

Adapter si nécessaire, mais conserver cette séparation logique.

------------------------------------------------------------------------

# 44. Pages principales

Créer au minimum :

``` text
/login
/register
/dashboard
/sites
/sites/create
/articles
/articles/{id}
/articles/{id}/edit
/audits
/settings
```

------------------------------------------------------------------------

# 45. Page Articles --- comportement attendu

Flux :

``` text
Utilisateur connecté
        ↓
Choisit un site WordPress
        ↓
Connexion / synchronisation
        ↓
Récupération catégories + articles
        ↓
Affichage des filtres
        ↓
Sélection d’une ou plusieurs catégories
        ↓
Tableau filtré AJAX
        ↓
Audit disponible
        ↓
Remarques affichées
        ↓
Clique "Éditer"
        ↓
Éditeur article
        ↓
Modification
        ↓
"Mettre à jour"
        ↓
API WordPress
        ↓
Nouvel audit
        ↓
Statut actualisé
```

------------------------------------------------------------------------

# 46. Expérience d'édition

L'utilisateur doit pouvoir corriger rapidement un article.

Sur l'écran d'édition, afficher une colonne latérale :

## Audit de l'article

Exemple :

-   [x] Image à la une
-   ⚠ Image dans le contenu
-   ⚠ Titre trop long
-   [x] Shortcode
-   [x] H1

Chaque problème doit être cliquable et amener l'utilisateur vers la zone
concernée si possible.

Exemple :

`Titre trop long → focus sur le champ titre`

`2 H1 détectées → afficher les H1 concernées`

`Image floue → afficher l’image concernée`

------------------------------------------------------------------------

# 47. Aperçu des problèmes

Dans le tableau, ne pas afficher de longs textes.

Utiliser : - badges ; - tooltip ; - bouton `Voir les problèmes`.

Exemple :

`⚠ 3 problèmes`

Au clic : modal :

``` text
Problèmes détectés

• Image à la une manquante
• Titre trop long
• 2 balises H1 détectées
```

------------------------------------------------------------------------

# 48. Synchronisation initiale

Quand un domaine est ajouté :

1.  normaliser l'URL ;
2.  tester `/wp-json/wp/v2/posts`;
3.  tester l'authentification si nécessaire ;
4.  récupérer les catégories ;
5.  récupérer les articles ;
6.  enregistrer les données ;
7.  lancer l'audit en arrière-plan ;
8.  afficher la progression.

Ne pas bloquer le navigateur pendant une synchronisation longue.

------------------------------------------------------------------------

# 49. Gestion des gros sites

Prévoir des sites avec plusieurs centaines ou milliers d'articles.

Utiliser : - pagination ; - jobs ; - batchs ; - cache ; - index DB ; -
requêtes optimisées.

Ne jamais faire :
`GET tous les articles → envoyer tous les articles au navigateur`.

------------------------------------------------------------------------

# 50. Responsive

Desktop : - sidebar fixe ; - contenu large ; - tableau complet.

Tablet : - sidebar adaptable ; - tableau scrollable horizontalement.

Mobile : - sidebar offcanvas ; - filtres dans un panneau ; - cartes ou
tableau responsive ; - actions facilement accessibles.

------------------------------------------------------------------------

# 51. Accessibilité

Respecter autant que possible : - labels de formulaires ; - contraste
; - focus visible ; - navigation clavier ; - `aria-label` pour boutons
icon-only ; - ne pas utiliser uniquement la couleur pour signaler une
erreur.

------------------------------------------------------------------------

# 52. Ce qu'il ne faut PAS faire

Ne pas : - créer une simple maquette statique ; - utiliser des données
fictives après la mise en place de l'API ; - coder tous les appels
WordPress dans les Controllers ; - stocker les secrets en clair ; -
charger tous les articles en une seule fois ; - analyser toutes les
images à chaque page ; - considérer une détection IA comme certaine ; -
modifier automatiquement les articles sans validation utilisateur ; -
ajouter React/Vue juste pour faire joli ; - ajouter trop de dépendances
; - surcharger l'interface avec des couleurs ; - créer une architecture
inutilement complexe.

------------------------------------------------------------------------

# 53. Priorités de développement

Développer par étapes.

## Phase 1 --- Fondation

-   Laravel
-   Bootstrap
-   Auth
-   Layout
-   Sidebar
-   Dashboard

## Phase 2 --- WordPress

-   Connexion site
-   Credentials sécurisés
-   Test API
-   Catégories
-   Articles
-   Pagination

## Phase 3 --- Gestion

-   Filtres multi-catégories
-   Recherche
-   Tableau
-   Édition
-   Mise à jour WordPress

## Phase 4 --- Audit

-   image à la une
-   images contenu
-   shortcode
-   titre
-   H1

## Phase 5 --- Analyse avancée

-   flou
-   pertinence des images
-   jobs
-   cache

## Phase 6 --- UX

-   AJAX
-   loaders
-   toasts
-   modals
-   responsive
-   animations

## Phase 7 --- qualité

-   sécurité
-   tests
-   gestion erreurs
-   performance
-   nettoyage du code

------------------------------------------------------------------------

# 54. Règle importante pour Claude Code

Avant d'écrire beaucoup de code :

1.  Inspecter le projet existant.
2.  Identifier la version Laravel/PHP.
3.  Vérifier la base de données.
4.  Vérifier les dépendances déjà installées.
5.  Ne pas écraser inutilement du code existant.
6.  Proposer une structure claire.
7.  Implémenter par petites étapes.
8.  Tester après chaque fonctionnalité importante.
9.  Corriger les erreurs avant de passer à l'étape suivante.

Si le projet est vide, initialiser proprement Laravel.

------------------------------------------------------------------------

# 55. Commandes et documentation

Créer également un fichier :

`README.md`

Il doit expliquer : - installation ; - `.env` ; - base de données ; -
migrations ; - seeders ; - lancement local ; - queue ; - configuration
WordPress ; - Application Password ; - configuration éventuelle du
fournisseur AI ; - tests.

Créer `.env.example` complet.

Ne jamais commit : - `.env` - credentials WordPress - API keys.

------------------------------------------------------------------------

# 56. Definition of Done

La fonctionnalité principale est considérée terminée lorsque :

-   [ ] utilisateur peut créer un compte ;
-   [ ] utilisateur peut se connecter ;
-   [ ] utilisateur peut ajouter un site WordPress ;
-   [ ] connexion WordPress fonctionne ;
-   [ ] catégories sont récupérées ;
-   [ ] articles sont récupérés ;
-   [ ] pagination fonctionne ;
-   [ ] filtres multi-catégories fonctionnent ;
-   [ ] recherche fonctionne ;
-   [ ] problèmes sont détectés ;
-   [ ] remarques sont affichées dans le tableau ;
-   [ ] statut `À corriger` apparaît en cas de problème ;
-   [ ] article peut être ouvert ;
-   [ ] titre peut être modifié ;
-   [ ] contenu peut être modifié ;
-   [ ] images peuvent être gérées ;
-   [ ] image mise en avant peut être modifiée ;
-   [ ] catégories peuvent être modifiées ;
-   [ ] article peut être envoyé à WordPress ;
-   [ ] nouvel audit est lancé après sauvegarde ;
-   [ ] les problèmes résolus disparaissent ;
-   [ ] interface responsive ;
-   [ ] erreurs API correctement gérées ;
-   [ ] credentials sécurisés ;
-   [ ] tests principaux passent.

------------------------------------------------------------------------

# 57. Instructions finales à Claude Code

Tu dois construire **ArticleGuard WP** comme une véritable application
Laravel/Bootstrap prête à être utilisée.

Commence par analyser le projet puis établis un plan d'implémentation
court.

Ensuite implémente progressivement les fonctionnalités.

Pour chaque étape : - explique brièvement ce qui est fait ; - modifie
les fichiers nécessaires ; - lance les tests/commandes pertinentes ; -
vérifie les erreurs ; - corrige les problèmes ; - ne passe pas à l'étape
suivante si la précédente est cassée.

Priorité absolue :

**fonctionnalité réelle \> architecture propre \> sécurité \>
performance \> design.**

Le design doit être inspiré des captures fournies : - sidebar
professionnelle ; - tableau de gestion ; - éditeur d'article ; - colonne
d'audit ; - filtres ; - badges ; - interface claire et légère.

Le résultat final doit donner l'impression d'un **outil SaaS
professionnel de gestion et d'audit de contenu WordPress**, et non d'un
simple projet étudiant ou d'une maquette.
