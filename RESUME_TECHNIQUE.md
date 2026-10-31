# Dahu File Cleanup — résumé technique

Document interne. L'extension publique est en anglais ; ce fichier est le seul
du dépôt où les origines Annad sont nommées.

Version 1.0.0, portée de `dahu-purge` 1.2.0 le 8 octobre 2026.

---

## 1. Portage depuis `dahu-purge`

### 1.1 L'arbitrage, et la lecture qui a été retenue

`dahu-purge` était câblée sur quatre cas Annad : factures PDF de *PDF Invoices
& Packing Slips*, devis PDF de *YITH Request a Quote*, étiquettes Chronopost, et
résidus de *WP Staging Pro*. Chaque catégorie avait son libellé, sa liste
d'extensions et son mode de scan **figés dans le code** ; seuls le chemin, la
rétention et l'activation étaient réglables.

Trois niveaux de généralisation avaient été écrits dans la feuille de route :

| | |
|---|---|
| **Minimal** | une liste de chemins et un âge. Simple, peu différenciant. |
| **Intermédiaire** | des règles nommées avec motif de fichier, âge et activation par règle. |
| **Poussé** | des conditions croisées (type de commande, statut, extension source), qui recoupent des extensions de nettoyage existantes. |

Hugo devait trancher. Faute de réponse et avec pour consigne de ne pas
m'arrêter, j'ai pris **l'intermédiaire**, qui était ma recommandation écrite, et
rien de ce qui a été fait n'est irréversible : aucun dépôt poussé, aucun zip,
rien touché sur la boutique. **À confirmer.**

### 1.2 Les catégories figées deviennent des règles nommées

Le modèle est maintenant : *n* règles, chacune portant son libellé, ses
dossiers, ses extensions, son âge, son mode de scan et son activation. Les
quatre catégories Annad ne disparaissent pas, elles deviennent des **modèles
prêts à l'emploi** qu'on insère en un clic (`Dahu_File_Cleanup_Rules::presets()`,
filtrable par `dahu_file_cleanup_presets`).

Chronopost n'y figure pas. Le dossier `wp-content/uploads/chronopost` était
réel, mais je ne sais pas quelle extension l'écrit, et un modèle qui nomme un
transporteur sans pouvoir nommer l'extension qui crée le dossier n'informe
personne. Côté Annad, c'est une règle saisie à la main. Un quatrième modèle a
été ajouté à la place : `wp-content/uploads/logs`, que plusieurs extensions
emploient.

### 1.3 Le prix de la généralisation, et comment il a été payé

**C'est le point important du portage.** Dans `dahu-purge`, la sûreté tenait à
une chose : les extensions étaient dans le code. L'administrateur pouvait
désigner un dossier, jamais dire « supprime tout ce qu'il contient ». Des
règles écrites par l'utilisateur transfèrent cette liste à la saisie, et la
propriété disparaît si on ne la remplace pas.

Elle est remplacée par six choses :

1. **La liste d'extensions est obligatoire et ne peut pas être vide.** Une
   règle sans extension ne sélectionne **rien** — et non pas tout. Il n'existe
   aucune syntaxe pour « tout ».
2. **Une liste noire d'extensions qu'aucune règle ne peut porter** : code,
   scripts, feuilles de style, balisage, configuration serveur, traductions
   (`Dahu_File_Cleanup_Rules::forbidden_extensions()`). Les archives et les
   vidages (`zip`, `sql`, `gz`, `wpstg`) en sont volontairement absents : c'est
   ce qu'une extension de sauvegarde laisse derrière elle, et c'est l'essentiel
   du propos.
3. **Les refus de chemins sont élargis**, et le bannissement porte sur le
   **sous-arbre entier** et non sur sa racine : `wp-admin`, `wp-includes`,
   `plugins`, `mu-plugins`, les racines de thèmes, `languages`, `upgrade`. Les
   refus propres à WP Staging (`cache/`, `tmp/`) disparaissent avec les
   catégories figées ; le modèle ne cible que `backups/` et `logs/` et le dit.
4. **Un plafond par règle et par exécution**, absent de la source.
5. **Un verrou** interdisant deux exécutions simultanées, absent de la source.
6. **La revalidation du dossier dans `delete_file()`** même quand l'appelant
   l'a déjà validé, parce que cette fonction est publique.

### 1.4 Ce qui n'est pas porté du tout

**Le flush de cache après mise à jour.** `dahu-purge` vidait Redis, OPcache et
WP Rocket après toute mise à jour, parce qu'un cache HTML resté en place avait
provoqué deux fois une boucle de connexion admin vers `upgrade.php`. Le
correctif est bon et le défaut était réel, mais il n'a rien à voir avec une
politique de conservation de fichiers : il nomme une extension commerciale par
sa fonction, et une extension qui s'appelle « File Cleanup » et vide des caches
est le genre de fourre-tout que wordpress.org refuse. Reste chez Annad.

**Le verrou de conservation des données personnelles.** Le deuxième module de
`dahu-purge` neutralise les cinq délais d'anonymisation automatique de
WooCommerce (`woocommerce_trash_pending_orders`,
`woocommerce_trash_failed_orders`, `woocommerce_trash_cancelled_orders`,
`woocommerce_anonymize_completed_orders`,
`woocommerce_delete_inactive_accounts`) en trois couches redondantes : lecture
forcée à vide, écriture refusée et journalisée, tâches planifiées
déprogrammées. Plus un bandeau sur l'écran WooCommerce, qui continue d'afficher
ses champs sans savoir qu'ils sont sans effet.

C'est l'idée la plus originale de l'extension source, et **elle ne supprime
rien** : elle empêche WooCommerce de supprimer. La mettre dans une extension
qui s'appelle « nettoyage de fichiers » serait le meilleur moyen que personne
ne la trouve. Proposition : une onzième extension,
`dahu-order-retention-lock`, dont le périmètre est écrit dans la feuille de
route. **Décision de Hugo.**

### 1.5 Pas de WooCommerce

La version publique n'en dépend pas, parce que rien de ce qui est porté ne sait
ce qu'est une commande : elle supprime des fichiers dans des dossiers. L'en-tête
ne déclare donc ni `WC requires at least` ni la compatibilité HPOS, et l'écran
vit sous **Outils**, pas sous WooCommerce ni sous Réglages — c'est un écran sur
lequel on revient, et l'aperçu et le journal en occupent la moitié.

La capacité reste `manage_options`, pas `manage_woocommerce` : supprimer des
fichiers sur le serveur n'est pas une décision de gestionnaire de boutique.

---

## 2. Les deux défauts trouvés dans la source

### 2.1 `RecursiveIteratorIterator` suit les liens symboliques de dossiers

`dahu_purge_scanner_categorie()` employait
`RecursiveDirectoryIterator` + `RecursiveIteratorIterator` avec
`FilesystemIterator::SKIP_DOTS`. Ce drapeau ne change rien au suivi des liens :
l'itérateur **descend** dans un sous-dossier qui est un lien symbolique, y
compris vers l'extérieur du site.

La suppression, elle, était sauve : `dahu_purge_supprimer_fichier()` vérifiait
que le `realpath` du fichier restait sous la racine validée, et un fichier
atteint par un lien n'y est pas. **Mais l'aperçu listait ces fichiers.** C'est
l'écran sur lequel on s'appuie pour décider d'activer une purge, et un aperçu
qui liste des fichiers qu'il ne supprimera pas est pire qu'inutile.

La version publique parcourt les dossiers **à la main**, avec une pile explicite
et un `isLink()` qui écarte l'entrée quoi qu'elle soit. Les liens écartés sont
**comptés** et affichés : sinon l'écart entre « fichiers dans le dossier » et ce
que montre un `ls` n'a pas d'explication.

Prouvé par injection : retirer le `isLink()` fait échouer l'assertion de
sélection à plat ; le retirer **en même temps** que le contrôle de `realpath`
par fichier fait apparaître le fichier extérieur dans la sélection. Les deux
défenses sont volontairement redondantes, donc l'injection les retire ensemble —
en retirer une seule donnerait un contrôle qui ne porte rien.

### 2.2 Le scan construit la liste complète en mémoire

`dahu_purge_scanner_categorie()` retournait **tous** les fichiers retenus dans
un tableau, utilisé tel quel par l'aperçu *et* par la purge réelle. Sur un
dossier de cent mille fichiers, c'est une erreur fatale de mémoire, sur le cron,
la nuit.

La version publique a un seul parcours avec un visiteur :
`Dahu_File_Cleanup_Scanner::walk( $rule, $visitor )`. L'aperçu collecte au plus
*n* fichiers pour l'affichage mais compte et totalise **tout** ; l'exécution
supprime au fil de l'eau et ne garde aucune liste. Le visiteur peut retourner
`false` pour arrêter le parcours, et c'est ainsi que le plafond est appliqué
sans que le parcours sache ce qu'est un plafond.

---

## 3. Le défaut introduit par le portage, et ce qui l'a trouvé

`code_roots()` passait les valeurs de `get_theme_roots()` par
`path_is_absolute()`. Or `get_theme_roots()` renvoie des chemins **relatifs à
`wp-content`** qui commencent par une barre oblique — `/themes` — que
`path_is_absolute()` qualifie très correctement d'absolus. `realpath('/themes')`
vaut `false`, l'entrée était écartée en silence, et **`wp-content/themes`
devenait une cible légale**.

Trouvé par le banc, pas par relecture. Et le test a été renforcé ensuite :
affirmer seulement que `wp-content/themes` est refusé aurait laissé passer la
même faute sur un site dont le dossier des thèmes est ailleurs — le refus
« dossier introuvable » aurait porté le costume de la bonne réponse. Le banc
affirme donc aussi que `code_roots()` **contient** une entrée `themes` pointant
sur le dossier réel.

La résolution se fait maintenant dans les deux sens, et trois sources
indépendantes sont consultées (`WP_CONTENT_DIR . '/themes'`, `get_theme_root()`,
`get_theme_roots()`, plus `$GLOBALS['wp_theme_directories']`), ce qui rend la
protection impossible à casser par une seule injection — l'injection retire
donc l'affectation qui les consomme.

---

## 4. Le banc, et ce qu'il a fallu corriger dans le banc lui-même

`_bench/t-file-cleanup.php` — 292 assertions, idempotent, sur un vrai
WordPress et un vrai système de fichiers. Chaque fichier dont il parle est un
fichier qu'il a créé ; chaque suppression a réellement eu lieu.

`_bench/prove-file-cleanup.py` — **39 injections, toutes attrapées.** C'est ce
script qui a montré que trois assertions passaient pour la mauvaise raison.

### 4.1 Les refus qui passaient par la même porte de sortie

Le test du joker affirmait que
`wp-content/uploads/bench-file-cleanup/*` était refusé. Il l'était — par
« dossier introuvable », puisque aucun dossier ne porte ce nom. Retirer le
contrôle de joker ne faisait donc rien échouer.

Vingt-et-un refus étaient écrits sur ce modèle. Ils affirmaient tous la même
chose et prouvaient un seul contrôle. Désormais l'assistant de test exige **un
fragment de la raison** retournée, et le banc crée deux dossiers portant
réellement un `*` et un `?` dans leur nom.

### 4.2 Le garde-fou qui empêchait une erreur fatale, et qu'on prenait pour le seul inutile

Retirer le contrôle d'octet nul faisait **mourir** le banc : en PHP 8,
`realpath()` lève une `ValueError` sur un chemin qui en contient un, et la
requête entière tombe. Le script de preuve lisait la sortie, n'y trouvait
aucune assertion échouée, et concluait que le contrôle ne mesurait rien.

L'assistant de test capture donc tout `Throwable` et le rapporte comme un
échec, au lieu de laisser la requête s'arrêter. Le contrôle est maintenant
prouvé — et la conclusion est plus forte que prévu : il n'empêche pas un
mauvais chemin, il empêche une erreur fatale sur une page où quelqu'un a collé
un chemin.

### 4.3 Deux défenses qui se couvraient l'une l'autre

Le test du lien symbolique dans `delete_file()` passait avec le `is_link()`
retiré, parce que le lien de la fixture pointait **hors** du dossier et que le
contrôle de racine l'attrapait. Il a fallu une fixture dont la cible est
**dans** le dossier nettoyé — c'est le seul cas où `is_link()` porte quelque
chose, et sans elle on supprime la cible en laissant le lien pendant : le
dossier a l'air nettoyé et ne l'est pas.

Cette fixture vit dans un dossier qu'aucune règle du banc ne cible. Mise dans
`docs/`, la section 8 l'aurait supprimée et la section 10 aurait testé un lien
mort — refusé pour la mauvaise raison.

### 4.4 Le contrôle qui ne porte rien, et qui reste

`is_file()` dans `delete_file()` ne change aucun résultat : `unlink()` refuse
un dossier de lui-même, y compris un dossier nommé `folder.pdf`. L'injection
correspondante a été **retirée** du script plutôt que laissée en échec
permanent — un échec qu'on apprend à ignorer est le pire que puisse enseigner
une suite de tests. Le contrôle reste dans le code, pour une raison écrite à
cet endroit : un refus motivé vaut mieux qu'un avertissement PHP sur un cron,
et `unlink()` sur un dossier n'a pas toujours refusé sur toutes les
plateformes.

---

## 5. Points d'architecture à connaître

### 5.1 Le drapeau « activée » n'est pas le dernier mot

`Dahu_File_Cleanup_Settings::runnable_rules()` écarte une règle activée qui a
perdu une de ses réponses. `sanitize()` refuse déjà d'enregistrer une règle
incomplète comme activée, mais une option modifiée à la main, une migration ou
une version antérieure moins prudente peuvent produire cet état, et le banc
l'écrit **directement dans l'option** pour que `sanitize()` ne soit pas la
chose testée.

### 5.2 Vide n'est pas zéro

Une rétention vide reste vide et signifie « non définie ». Zéro signifierait
« supprime tout, quel que soit l'âge », et ce n'est pas une réponse qu'un champ
laissé vide donne. Une valeur saisie est ramenée à un jour au minimum.

### 5.3 La clé d'une règle ne change jamais

Elle est dérivée du libellé une fois, à la création. C'est contre elle que les
lignes du journal ont été écrites et c'est elle qu'un filtre cible. `sanitize()`
procède donc en deux passes : les lignes qui arrivent avec une clé la gardent
intacte, les nouvelles en reçoivent une, rendue unique contre celles de la
première passe. Mêmes mécanique et même raison que dans `dahu-user-pricing`.

Les espaces deviennent des traits d'union **avant** `sanitize_key()`, qui les
supprime : sans cela « Old invoices » et « Oldinvoices » donnent la même clé.
Erreur déjà faite sur `dahu-user-pricing`.

### 5.4 Un seul `unlink()`

`Dahu_File_Cleanup_Runner::delete_file()`. Il revalide tout, y compris le
dossier, parce qu'il est public et que le script WP-CLI s'en sert. La validation
du dossier est mémoïsée par racine pour la requête : l'alternative est un
`realpath()` et une poignée de résolutions de dossiers pour chacun de ce qui
peut être cinquante mille fichiers, et la réponse ne peut pas différer entre
deux fichiers du même dossier dans la même requête.

### 5.5 Le journal n'est pas autochargé

Deux cents lignes dans une option autochargée, c'est quelques kilo-octets
ajoutés à **chaque** vue de page du site, pour un tableau que deux personnes
regarderont. Affirmé par le banc contre le vrai jeu autochargé, après vidage du
cache, parce que `wp_load_alloptions()` mémoïse.

### 5.6 L'avertissement légal est permanent et unique

`dahu-purge` portait un drapeau `avertissement_legal` par catégorie, posé à la
main sur les deux qui contenaient des factures. Avec des règles écrites par
quelqu'un d'autre, sur des dossiers que personne ici n'a vus, il n'y a aucun
moyen honnête de décider quelle règle le mérite. Toutes l'ont donc, une fois,
en haut de l'écran.

---

## 6. Modèle de données

| Clé | Contenu |
|---|---|
| `dahu_file_cleanup_settings` | `rules` (clé → règle), `max_per_run`, `delete_data_on_uninstall`. Autochargée. |
| `dahu_file_cleanup_log` | Au plus 200 lignes, plus récente d'abord. **Non autochargée.** |
| `dahu_file_cleanup_running` | Transitoire, 900 s, verrou d'exécution. |
| `dahu_file_cleanup_daily` | Événement cron quotidien. |

Une règle : `label`, `paths` (une par ligne), `extensions` (tableau),
`days` (entier ou `''`), `recursive`, `enabled`.

Aucune table, aucune méta. La désinstallation ne supprime **aucun fichier** sur
le disque, jamais, et ne supprime les deux options que si le réglage le demande.

---

## 7. Ce qui reste à vérifier sur une vraie installation

1. **Un gros dossier.** Le parcours est en flux et le plafond borne la
   suppression, mais rien ici n'a été mesuré au-delà de quelques centaines de
   fichiers. À essayer sur les `yith_ywraq` d'Annad, qui ont dix ans de
   sous-dossiers `{année}/{mois}`.
2. **`wp-content` hors racine web.** `allowed_roots()` accepte trois racines
   pour cela, mais le banc n'a qu'une installation standard.
3. **Un dossier `uploads` déplacé** par `UPLOADS` ou par `upload_path`.
4. **Les quatre modèles**, sur un site qui a réellement ces extensions
   installées : le dossier existe-t-il encore à l'emplacement annoncé ?
5. **Un hébergement avec `open_basedir`.** `realpath()` échoue alors sur un
   chemin légitime hors du périmètre, et le refus affiché sera « dossier
   introuvable », ce qui est trompeur. À voir si un message distinct vaut la
   peine.
6. **Windows.** `wp_normalize_path()` majuscule la lettre de lecteur, les
   comparaisons sont sensibles à la casse, et rien de tout cela n'est éprouvé
   ici.
