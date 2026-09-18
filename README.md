# ArticleGuard WP

**WordPress Content Audit & Management** — back-office centralisé de gestion,
d'audit et de correction des articles WordPress.

ArticleGuard se connecte à un site WordPress via son API REST, récupère ses
catégories et ses articles, détecte automatiquement les défauts récurrents
(image à la une manquante, shortcode résiduel, H1 trop long, H1 multiples,
image floue…) et permet de corriger les articles depuis un éditeur intégré, les
modifications étant renvoyées à WordPress.

---

## Sommaire

- [Pile technique](#pile-technique)
- [Installation](#installation)
- [Configuration](#configuration-env)
- [Lancement](#lancement)
- [File d'attente](#file-dattente)
- [Connecter un site WordPress](#connecter-un-site-wordpress)
- [Règles d'audit](#règles-daudit)
- [Analyse des images](#analyse-des-images)
- [Sécurité](#sécurité)
- [Tests](#tests)
- [Architecture](#architecture)

---

## Pile technique

| Composant   | Version utilisée                          |
|-------------|-------------------------------------------|
| PHP         | 8.2+                                      |
| Laravel     | 12                                        |
| Base        | MySQL / MariaDB                           |
| Front       | Bootstrap 5, JavaScript vanilla (modules) |
| Build       | Vite                                      |
| Images      | GD (variance du Laplacien)                |

Aucun framework front lourd : l'interface est en Blade + Bootstrap, avec des
modules JavaScript natifs pour les interactions AJAX.

---

## Installation

```bash
# 1. Dépendances
composer install
npm install

# 2. Environnement
cp .env.example .env
php artisan key:generate

# 3. Base de données (voir .env plus bas)
php artisan migrate

# 4. Assets
npm run build      # ou `npm run dev` pendant le développement
```

### Extensions PHP requises

`pdo_mysql`, `mbstring`, `openssl`, `dom`, `fileinfo`, `curl`, et **`gd`** pour
l'analyse de netteté des images. Sans GD, l'application fonctionne : seule la
détection de flou est inactive (les dimensions restent vérifiées).

Sous XAMPP, décommenter dans `php.ini` :

```ini
extension=gd
extension=zip
```

---

## Configuration (`.env`)

### Base de données

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=articleguard_wp
DB_USERNAME=root
DB_PASSWORD=
```

Créer la base au préalable :

```sql
CREATE DATABASE articleguard_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Appels vers WordPress

```dotenv
WP_HTTP_TIMEOUT=20          # timeout par requête (s)
WP_HTTP_CONNECT_TIMEOUT=8   # timeout de connexion (s)
WP_HTTP_RETRY_TIMES=2       # nouvelles tentatives (erreurs réseau uniquement)
WP_HTTP_RETRY_SLEEP=300     # attente entre tentatives (ms)
WP_PER_PAGE=100             # taille de page demandée à l'API WordPress
```

### Protection SSRF

```dotenv
AG_SSRF_ALLOW_PRIVATE=false   # true uniquement pour un WordPress local de dev
AG_SSRF_ALLOWLIST=            # hôtes autorisés malgré la règle, séparés par des virgules
```

Pour travailler contre un WordPress local (`http://localhost:8080`), ajouter
l'hôte à l'allowlist plutôt que d'ouvrir toutes les plages privées :

```dotenv
AG_SSRF_ALLOWLIST=localhost
```

### Règles d'audit et seuils

```dotenv
AG_RULE_FEATURED_IMAGE=true
AG_RULE_BODY_IMAGE=false   # un article sans image n'est pas un défaut en soi
AG_RULE_BROKEN_IMAGE=true   # images du contenu qui ne s'affichent pas
AG_RULE_SHORTCODE=true
AG_RULE_LONG_TITLE=true
AG_RULE_H1=true
AG_RULE_MISSING_H1=false     # signaler aussi l'absence de H1
AG_RULE_MISSING_H2=false     # signaler l'absence de H2
AG_RULE_IMAGE_BLUR=true
AG_RULE_IMAGE_RELEVANCE=true

AG_TITLE_MAX_WORDS=20    # nombre maximal de mots du H1 (titre)
AG_BLUR_THRESHOLD=100
AG_MIN_IMAGE_WIDTH=600
AG_MIN_IMAGE_HEIGHT=400
AG_RELEVANCE_THRESHOLD=0.35
```

Ces valeurs sont les **valeurs par défaut** ; chaque utilisateur peut les
surcharger depuis la page **Paramètres** de l'application.

### Fournisseur d'analyse de pertinence (optionnel)

```dotenv
AG_RELEVANCE_DRIVER=heuristic   # heuristic | null | (votre pilote)
AG_VISION_API_KEY=
AG_VISION_MODEL=
AG_VISION_ENDPOINT=
```

- `heuristic` — analyse **locale**, sans appel externe : compare le vocabulaire
  décrivant l'image (nom de fichier, `alt`, légende) à celui de l'article.
- `null` — analyse désactivée, aucune remarque n'est produite.

Pour brancher un fournisseur de vision distant, implémenter
`ImageRelevanceAnalyzerInterface` et l'enregistrer dans
`AppServiceProvider::register()` — le moteur d'audit n'a pas à être modifié.

---

## Lancement

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # rechargement des assets pendant le développement
```

Créer un compte sur `/register`, puis connecter un site depuis `/sites/create`.

---

## File d'attente

Les traitements longs — synchronisation d'un site, audit d'un site entier,
analyse des images — sont exécutés en file d'attente afin de ne jamais bloquer
le navigateur.

```bash
php artisan queue:work
```

`QUEUE_CONNECTION=database` par défaut (la table `jobs` est créée par les
migrations).

**Sans worker en cours d'exécution :**

- la synchronisation d'un site ne démarre pas ;
- les audits en masse restent en attente ;
- l'audit d'un article depuis son écran d'édition (« Relancer ») fonctionne
  quand même : il s'exécute en direct, images comprises.

L'application détecte ce cas (`App\Services\QueueHealth`) : un bandeau
d'avertissement apparaît sur toutes les pages et le suivi de synchronisation
s'arrête au lieu de tourner dans le vide. Le worker est lancé automatiquement
par `composer dev`, qui démarre serveur, file, logs et Vite ensemble.

### Remplir un site immédiatement, sans worker

```bash
php artisan wp:sync                      # tous les sites : synchronise puis audite
php artisan wp:sync bijouteries.top      # un seul site
php artisan wp:sync bijouteries.top --quick     # sans les règles réseau (images) : quelques secondes
php artisan wp:sync bijouteries.top --all       # réaudite même les articles déjà à jour
php artisan wp:sync bijouteries.top --no-audit  # synchronisation seule
```

La commande exécute le même service que les jobs (`WordPressSyncService` puis
`AuditService`), mais en direct et avec une barre de progression. Elle affiche
en fin de course le nombre d'articles, ceux à corriger et le total des
problèmes ouverts. C'est le chemin le plus court pour une première mise en
route :

```text
Catégories ............... 6
Articles synchronisés .... 59
Total articles ........... 59
À corriger ............... 28
Problèmes ouverts ........ 55
```

`--quick` saute les règles qui téléchargent les images (flou, résolution,
pertinence) : utile pour voir immédiatement les remarques de titre, H1,
shortcodes et images manquantes, quitte à relancer ensuite un audit complet.

---

## Connecter un site WordPress

1. **Créer une Application Password** dans WordPress :
   *Utilisateurs → Profil → Application Passwords*. Le compte doit pouvoir
   modifier les articles concernés.
2. Dans ArticleGuard : **Sites WordPress → Connecter un site**.
3. Renseigner le nom, le domaine (`https://exemple.com`), l'identifiant
   WordPress et l'Application Password.

La connexion est testée immédiatement, puis les catégories et articles sont
récupérés en arrière-plan et un premier audit est lancé.

Sans identifiants, le site est utilisable **en lecture seule** : les articles
publiés sont listés et audités, mais non modifiables.

### Le rôle du compte WordPress compte autant que le mot de passe

Un compte peut être parfaitement authentifié et n'avoir malgré tout **aucun
droit d'écriture**. Un rôle *abonné* ou *client* lit l'API REST sans pouvoir
demander `context=edit` ni `status=any` : WordPress répond alors `403
rest_forbidden_context` ou `400 rest_invalid_param` sur le paramètre `status`.

ArticleGuard le détecte au test de connexion (capacité `edit_posts` lue sur
`/wp/v2/users/me`) et bascule automatiquement en lecture seule plutôt que
d'échouer : les **articles publiés sont récupérés et audités** normalement. Le
site porte alors le badge **Lecture seule**, et l'éditeur prévient que
WordPress refusera l'enregistrement.

Pour pouvoir corriger depuis ArticleGuard, le compte doit avoir au minimum le
rôle **Auteur** — *Éditeur* ou *Administrateur* pour agir sur les articles
d'autres auteurs.

### Authentification : ce que WordPress accepte

ArticleGuard ne se connecte **jamais** à `wp-admin` comme un navigateur. Il
dialogue uniquement avec l'API REST (`/wp-json/wp/v2`) en **Basic Auth**, le
mécanisme officiel des *Application Passwords* WordPress (≥ 5.6).

Conséquence directe : **le mot de passe du compte wp-admin ne fonctionne pas.**
L'API REST ne l'accepte dans aucun cas. Il faut une Application Password
dédiée :

1. se connecter à WordPress ;
2. ouvrir *Utilisateurs → Profil* ;
3. section *Application Passwords* : nommer l'accès « ArticleGuard », puis
   *Add New* ;
4. copier la valeur affichée — 24 caractères présentés
   `xxxx xxxx xxxx xxxx xxxx xxxx`. Elle n'est **montrée qu'une seule fois**.

L'identifiant à saisir reste le nom d'utilisateur (ou l'e-mail) du compte
WordPress. Les espaces de l'Application Password sont décoratifs : ArticleGuard
les retire automatiquement, tout comme les espaces insécables ramenés par un
copier-coller.

### Diagnostiquer un refus d'authentification

```bash
php artisan wp:diagnose                    # tous les sites
php artisan wp:diagnose exemple.com        # un domaine
php artisan wp:diagnose 3                  # un identifiant de site
```

La commande contrôle, dans l'ordre : validité de l'URL, joignabilité de l'API
REST, prise en charge des Application Passwords annoncée par le site, présence
et format des identifiants, puis l'authentification réelle.

Les causes possibles d'un 401 et leur correction :

| Code WordPress | Cause | Correction |
|----------------|-------|------------|
| `incorrect_password` | le secret n'est pas une Application Password valide (souvent le mot de passe du compte) | en générer une dans *Utilisateurs → Profil* |
| `invalid_username` | identifiant inconnu | utiliser le login ou l'e-mail du compte propriétaire |
| `rest_not_logged_in` | WordPress n'a vu **aucune** tentative d'authentification | voir ci-dessous |

`rest_not_logged_in` a deux causes, que `wp:diagnose` sépare en renvoyant un
identifiant factice au site :

1. **aucune Application Password n'a encore été créée sur le site** —
   WordPress n'active son gestionnaire qu'à partir de la première ;
2. **l'hébergeur supprime l'en-tête `Authorization` avant PHP** (Apache/CGI,
   FastCGI). À ajouter dans le `.htaccess` du site WordPress, avant les règles
   WordPress :

   ```apache
   <IfModule mod_rewrite.c>
     RewriteEngine On
     RewriteCond %{HTTP:Authorization} ^(.*)
     RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]
   </IfModule>
   ```

   ou activer `CGIPassAuth On` côté serveur.

Enfin, les Application Passwords exigent **HTTPS** et peuvent être désactivées
par un plugin de sécurité : le site cesse alors de les annoncer dans la clé
`authentication` de `/wp-json`, ce que `wp:diagnose` signale explicitement.

### Endpoints utilisés

| Endpoint                   | Usage                                       |
|----------------------------|---------------------------------------------|
| `GET /wp-json`             | test de joignabilité                        |
| `GET /wp/v2/users/me`      | validation de l'Application Password        |
| `GET /wp/v2/categories`    | catégories (paginées)                       |
| `GET /wp/v2/posts`         | articles (paginés, `context=edit` si auth)  |
| `POST /wp/v2/posts/{id}`   | mise à jour d'un article                    |
| `GET|POST /wp/v2/media`    | médiathèque et upload                       |

La pagination suit les en-têtes `X-WP-Total` et `X-WP-TotalPages` : aucune
requête ne suppose que tout tient en une page.

---

## Règles d'audit

| Règle                | Remarque affichée                       | Sévérité | Réseau | Défaut  |
|----------------------|-----------------------------------------|----------|--------|---------|
| `featured_image`     | Image à la une manquante / inaccessible | warning  | non    | activée |
| `broken_image`       | Image cassée dans le contenu            | error    | oui    | activée |
| `body_image`         | Image dans le contenu manquante         | warning  | non    | **désactivée** |
| `long_title`         | H1 trop long (max : 20 mots)            | warning  | non    | activée |
| `shortcode`          | Shortcode détecté / Crochet détecté     | info     | non    | activée |
| `h1`                 | *N* balises H1 détectées / H1 manquant  | error    | non    | activée |
| `missing_h2`         | H2 manquant                             | info     | non    | désactivée |
| `image_blur`         | Image potentiellement floue             | warning  | oui    | activée |
| `image_relevance`    | Image potentiellement incohérente       | info     | oui    | activée |

Un article sans aucune image n'est pas un défaut en soi : `body_image` est donc
**désactivée par défaut**. Ce qui compte est l'état des images réellement
présentes — cassées, floues, de faible résolution ou sans rapport avec le sujet.
La règle reste disponible dans *Paramètres* pour les sites où chaque article
doit être illustré.

Désactiver une règle **efface** ses remarques existantes au prochain audit ;
elles ne sont pas marquées « corrigées », puisque rien ne l'a été.

Deux contrôles distincts portent sur les H1, à ne pas confondre :

- **`long_title`** contrôle la longueur du **titre WordPress**, celui que le
  thème rend en `<h1>` sur la page publique. La mesure porte sur le **nombre de
  mots** — 20 au maximum par défaut (`AG_TITLE_MAX_WORDS`, modifiable par
  utilisateur dans *Paramètres*) — et non sur le nombre de caractères : dix mots
  courts restent lisibles là où la même longueur en caractères peut n'en cacher
  que trois, interminables. Les séparateurs isolés (« : », « — ») ne comptent
  pas pour des mots.
- **`h1`** compte les balises `<h1>` **présentes dans le contenu**. Le titre
  WordPress n'y est pas compté : il est rendu séparément par le thème. Plusieurs
  H1 dans le corps de l'article restent donc une anomalie.

### Images cassées

`broken_image` télécharge chaque image du contenu et signale celles qui ne
s'afficheront pas chez le visiteur : URL en 404 ou en erreur serveur, domaine
qui ne résout plus, corps vide, ou réponse qui n'est pas une image (page
d'erreur HTML servie en 200). Le HTML de l'article, lui, reste valide : le
défaut est invisible sans cette requête.

Sont volontairement **exclues** les images que le navigateur sait afficher mais
que GD n'a pas su décoder ici, ainsi que les images simplement trop lourdes :
elles ne sont pas cassées. L'image à la une n'est pas reprise non plus, elle
relève de `featured_image`.

### Crochets et shortcodes

`shortcode` ne se limite pas à la syntaxe WordPress canonique (`[galerie]`,
`[encadre couleur="bleu"]…[/encadre]`). **Tout crochet présent dans le texte
visible** est signalé, y compris les résidus de génération qui n'ont pas la
forme d'un shortcode :

```text
[public; text...script etc]
```

La remarque vaut pour le contenu **et pour le titre**, contrôlés séparément.
Les crochets présents dans un attribut HTML ou dans du JSON embarqué sont
ignorés : ils ne sont pas visibles par le lecteur.

### États d'un article

| État        | Signification                                                  |
|-------------|----------------------------------------------------------------|
| *(vide)*    | jamais audité                                                  |
| `OK`        | audité, aucun problème, aucun historique                       |
| `À corriger`| au moins un problème ouvert                                    |
| `Corrigé`   | des problèmes existaient et un **nouvel audit** les a vus résolus |

Enregistrer un article depuis l'éditeur ne suffit jamais à le faire passer en
`Corrigé` : c'est l'audit relancé après la sauvegarde qui le constate.

### Changer le statut à la main

Dans la colonne **Statut** du tableau, un article qui présente des problèmes
— ou qui en a présenté — affiche un **sélecteur** : `À corriger` / `Corrigé`.
Il sert à déclarer une correction faite ailleurs, typiquement directement dans
WordPress, sans attendre un nouvel audit.

- Passer à **`Corrigé`** clôture les remarques ouvertes, qui disparaissent du
  tableau. Elles restent en base, marquées `resolved_manually`.
- Revenir à **`À corriger`** rouvre exactement ces remarques-là. Celles qu'un
  audit avait réellement vues résolues ne sont jamais ressuscitées.
- Un article en **`OK`** n'a pas de sélecteur : « aucun problème détecté » est
  un constat du moteur d'audit, pas une déclaration.

Le moteur garde le dernier mot : si le défaut est toujours présent, le prochain
audit rouvre la remarque et le statut repasse à `À corriger`. La date du
basculement manuel est conservée (`status_set_manually_at`) et signalée sous le
sélecteur.

### Ajouter une règle

1. Créer une classe dans `app/Services/Audit/Rules/` implémentant `AuditRule`.
2. L'ajouter à `AppServiceProvider::$auditRules`.
3. Ajouter sa clé dans `config/articleguard.php` (`rules`).

`issueTypes()` déclare les types de problèmes émis par la règle : le moteur ne
clôture que les problèmes des règles réellement exécutées, de sorte que
désactiver une règle ne fasse jamais croire à une correction.

---

## Analyse des images

La netteté est estimée par la **variance du Laplacien** : l'image est réduite,
convertie en niveaux de gris, puis le noyau `[[0,1,0],[1,-4,1],[0,1,0]]` est
appliqué. Une image nette conserve des transitions marquées (variance élevée) ;
une image floue les a lissées (variance basse).

Il s'agit d'une **heuristique** : le seuil est configurable et la remarque parle
d'image « potentiellement » floue.

Coût maîtrisé :

- chaque URL n'est téléchargée qu'une fois, le résultat étant mémorisé dans la
  table `image_analyses` et réutilisé tant que l'URL ne change pas ;
- l'analyse s'exécute en file d'attente, jamais pendant l'affichage du tableau ;
- au plus `AG_IMAGE_MAX_BYTES` octets sont téléchargés, avec un timeout dédié.

### Images « IA »

ArticleGuard **ne prétend pas** déterminer qu'une image est générée par IA :
ce n'est pas établissable de façon fiable à partir d'une URL. Le besoin réel —
repérer une image sans rapport avec le sujet — est traité par
`ImageRelevanceAnalyzerInterface`, dont le verdict peut être `relevant`,
`possibly_incoherent` ou `unknown`. Seul `possibly_incoherent` produit une
remarque, et uniquement au-delà du seuil configuré.

---

## Sécurité

- **CSRF** sur tous les formulaires et appels AJAX.
- **Policies** : chaque site et chaque article ne sont accessibles qu'à leur
  propriétaire.
- **Secrets chiffrés** : l'Application Password est chiffrée par Laravel
  (cast `encrypted`) et n'est jamais réaffichée en clair — seuls les quatre
  derniers caractères sont visibles.
- **Protection SSRF** : le domaine saisi est normalisé, son protocole et son
  port vérifiés, puis résolu ; toute adresse privée, loopback, lien-local
  (`169.254.169.254`) ou réservée est refusée. Le contrôle est rejoué avant
  chaque appel sortant, y compris au téléchargement des images.
- **XSS** : le HTML provenant de WordPress n'est jamais injecté brut. L'aperçu
  est assaini côté serveur (`HtmlContent::sanitized()`) et l'éditeur visuel côté
  client (`sanitize-html.js`). Le HTML d'origine reste intact dans l'onglet
  « Code source » et repart inchangé vers WordPress tant qu'il n'est pas édité
  visuellement.
- **Journaux** : connexions, synchronisations, mises à jour et erreurs API sont
  tracées. Les mots de passe et Application Passwords ne sont **jamais** logués.
- **Messages d'erreur** : l'utilisateur voit « Impossible de contacter le site
  WordPress… », jamais « cURL error 28 » ; le détail technique reste dans les
  logs Laravel.

---

## Tests

```bash
php artisan test                      # toute la suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

Les tests s'exécutent sur SQLite en mémoire et **ne contactent aucun site réel** :
toutes les requêtes HTTP sont simulées avec `Http::fake()`.

Couverture :

| Fichier                                  | Objet                                                      |
|------------------------------------------|------------------------------------------------------------|
| `tests/Unit/UrlGuardTest.php`             | normalisation d'URL, blocage SSRF                          |
| `tests/Unit/AuditRulesTest.php`           | chaque règle d'audit isolément                             |
| `tests/Unit/ImageAnalysisTest.php`        | score de netteté, cache, pertinence                        |
| `tests/Feature/AuthenticationTest.php`    | inscription, connexion, accès protégés                     |
| `tests/Feature/WordPressApiServiceTest.php` | pagination, médias, mise à jour, traduction des erreurs  |
| `tests/Feature/SyncAndAuditTest.php`      | synchronisation, cycle de vie des problèmes                |
| `tests/Feature/ArticleManagementTest.php` | filtres, recherche, pagination, édition, autorisations     |
| `tests/Feature/SiteManagementTest.php`    | connexion d'un site, chiffrement des secrets, paramètres   |

---

## Architecture

```text
app/
├── Http/
│   ├── Controllers/        ArticleController, SiteController, AuditController…
│   └── Requests/           validation (StoreSiteRequest, UpdateArticleRequest…)
├── Jobs/                   SynchronizeSiteJob, AuditSiteJob, AuditArticleJob
├── Models/                 WordpressSite, WordpressArticle, ArticleAuditIssue…
├── Policies/               WordpressSitePolicy, WordpressArticlePolicy
├── Services/
│   ├── WordPress/          WordPressApiService, WordPressSyncService,
│   │                       WordPressArticleService, WordPressMediaService
│   └── Audit/              AuditService, ImageQualityAnalyzer,
│       ├── Rules/          FeaturedImageRule, BodyImageRule, ImageBlurRule…
│       └── Relevance/      ImageRelevanceAnalyzerInterface + implémentations
└── Support/                UrlGuard, HtmlContent, IssueCatalog

resources/
├── views/                  layouts, auth, dashboard, sites, articles, audits
├── scss/app.scss           design system + Bootstrap 5 personnalisé
└── js/modules/             articles-table, editor, media-picker, sites, donut
```

### Principes

- **Aucun appel HTTP dans un contrôleur** : tout passe par les services
  `Services/WordPress`.
- **WordPress reste la source de vérité** ; la base locale est un cache de
  travail qui rend possibles filtrage, recherche, pagination et audit sans
  solliciter l'API à chaque clic.
- **Seuls les champs modifiés** sont envoyés lors d'une mise à jour, afin de ne
  pas écraser des changements faits ailleurs entre-temps.
- **Le moteur d'audit est modulaire** : ajouter une règle ne demande de toucher
  ni au moteur, ni aux contrôleurs, ni aux vues.

---

## Fichiers à ne jamais committer

`.env`, les identifiants WordPress et toute clé d'API. Le `.gitignore` de
Laravel les couvre déjà ; `.env.example` contient la liste complète des
variables, sans valeurs sensibles.
