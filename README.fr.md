# Dahu File Cleanup

Une politique de conservation pour les fichiers générés qui s'accumulent sur un
serveur WordPress : des règles nommées avec leurs dossiers, leurs extensions et
leur âge maximal, un aperçu qui montre chaque fichier avant toute suppression,
une exécution quotidienne, un plafond par exécution, et un journal.

Sous licence MIT. Nécessite WordPress 6.0 et PHP 7.4. Pas de WooCommerce, aucun
service externe.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## À quoi ça sert

Factures PDF, étiquettes d'expédition, devis PDF, archives de sauvegarde,
fichiers d'export, journaux de débogage. Des extensions les produisent,
personne ne les supprime, et un an plus tard le disque est plein de documents
qui ont servi une semaine. Les supprimer à la main, c'est ouvrir un client SFTP
et juger mille noms de fichiers ; les supprimer en une ligne de shell, c'est
être certain, à minuit, d'un `find -delete` écrit une seule fois.

Cette extension est l'entre-deux : vous écrivez la politique une fois, en
français, et elle tourne chaque nuit.

## Une règle

Cinq réponses :

| | |
|---|---|
| **Nom** | Ce qui apparaît dans le journal |
| **Dossiers** | Un par ligne, relatifs à la racine WordPress |
| **Extensions** | `pdf, zip, log` — c'est toute la sélection |
| **Plus vieux que** | En jours, mesuré sur la date de modification |
| **Sous-dossiers** | Descendre ou non |

Rien dans une règle n'est un motif. Il n'existe aucune façon d'écrire « tout ce
qui est dans ce dossier », parce que la liste d'extensions est le seul sélecteur
et qu'elle ne peut pas être vide : une règle sans extension ne sélectionne
**rien**, elle ne sélectionne pas tout.

Une règle sans rétention, sans extension, sans dossier ou sans nom **ne peut pas
être activée**. L'écran dit laquelle des réponses manque, et un drapeau
d'activation envoyé sur une règle incomplète est refusé plutôt qu'honoré : une
règle ne peut pas promettre au moteur quelque chose qu'elle n'a aucun moyen de
faire.

## Ce qu'elle refuse de toucher

Les dossiers :

- Tout chemin dont la résolution sort du site. Les chemins sont jugés sur
  `realpath()`, jamais sur ce qui a été saisi : un `..` ou un lien symbolique ne
  peut donc pas élargir une règle.
- La racine WordPress, `wp-content`, et la racine d'`uploads`. Il faut cibler un
  sous-dossier.
- Tout ce qui se trouve dans `wp-admin`, `wp-includes`, les dossiers
  d'extensions, d'extensions obligatoires ou de thèmes, `wp-content/languages`
  et `wp-content/upgrade`. L'interdiction porte sur le **sous-arbre entier**, pas
  seulement sur sa racine : une extension qui écrit son cache dans son propre
  dossier, ça existe, et le nettoyer n'est toujours pas le travail de cet outil.
- Les jokers, où qu'ils se trouvent.

Les fichiers :

- **Les liens symboliques ne sont jamais suivis ni supprimés**, dans les deux
  modes de scan. Un lien reste exactement où il est, et ce qu'il désigne n'est
  pas touché.
- Les extensions de code, de feuille de style, de balisage, de configuration
  serveur et de traduction — `php`, `phtml`, `js`, `css`, `html`, `htaccess`,
  `ini`, `env`, `mo`, `po` et les autres — ne peuvent pas figurer sur une règle.
  Un fichier PHP dans un dossier d'`uploads` est soit une erreur qu'il faut
  voir, soit un shell qu'il faut garder comme preuve, et dans aucun des deux cas
  quelque chose à supprimer automatiquement.
- Les archives et les vidages n'y sont volontairement **pas** : `zip`, `sql`,
  `gz` et `wpstg` sont ce qu'une extension de sauvegarde laisse derrière elle, et
  les faire tourner est l'essentiel du propos.

Toute suppression passe par une seule fonction, qui résout à nouveau le fichier,
vérifie que c'est bien un fichier ordinaire, qu'il est sous le dossier validé et
que son extension figure sur la règle — juste avant de le supprimer, et non au
moment où il a été sélectionné. Le système de fichiers peut changer entre un
scan et une suppression, et un contrôle fait sur l'ancien ne prouve rien sur le
nouveau.

## L'aperçu

L'aperçu liste ce que chaque règle supprimerait maintenant, y compris les règles
désactivées, et rapporte pour chaque dossier :

| | |
|---|---|
| Dossier saisi | et **le chemin absolu auquel il a été résolu** |
| Fichiers | fichiers ordinaires trouvés, et nombre de liens symboliques écartés |
| Bonne extension | combien la liste de la règle en sélectionne |
| Plus ancien / Plus récent | les dates de modification réellement rencontrées |
| Retenus | ce qui serait supprimé |

Ces colonnes existent parce qu'un aperçu vide a au moins quatre causes — le
dossier n'est pas là où vous le croyez, il ne contient rien avec cette
extension, tout y est plus récent que la rétention, ou il est illisible — et
que, vues de l'extérieur, elles sont identiques. Deviner entre elles au-dessus
des factures de quelqu'un n'est pas acceptable.

Il y a un avertissement déduit. Quand beaucoup de fichiers portent une extension
sélectionnée et qu'**aucun** n'est assez ancien, l'écran le dit : c'est la
signature de dates de modification réécrites en bloc par un clonage ou une
restauration, après quoi aucune rétention ne peut trouver quoi que ce soit
d'ancien, quel que soit l'âge réel des fichiers. Un seuil de volume est
nécessaire avant qu'il se déclenche, parce qu'un petit dossier légitimement tout
récent a la même forme et n'a rien d'anormal.

## Le plafond

Une exécution arrête une règle une fois qu'elle a supprimé un nombre donné de
fichiers — 2000 par défaut — l'inscrit au journal, et laisse le reste à
l'exécution suivante.

Il est là pour le jour où un dossier est élargi d'une ligne et se met à
correspondre à quarante mille fichiers. Une exécution qui s'arrête et le signale
est récupérable ; une exécution qui ne s'arrête pas, non. Il garde aussi un
parcours nocturne de gros arbre dans le temps et la mémoire qu'un hébergement
mutualisé accorde.

## L'exécution

L'exécution programmée est quotidienne, à 3 h du matin, heure du site. Elle est
aussi replanifiée au chargement de n'importe quelle page d'administration si
elle a disparu, ce qu'une extension d'optimisation agressive fait plus souvent
qu'on ne le voudrait : une politique de conservation qui s'est arrêtée en
silence est pire qu'une qui n'a jamais tourné.

Beaucoup d'hébergeurs définissent `DISABLE_WP_CRON` et appellent `wp-cron.php`
depuis un cron système, et beaucoup ne font ni l'un ni l'autre, auquel cas
« quotidien » signifie « au prochain visiteur ». L'écran le dit quand il le
détecte, et il existe une commande pour ça :

```bash
wp file-cleanup rules              # ce qui est configuré, et ce qui tournerait
wp file-cleanup preview            # diagnostic par dossier, ne supprime rien
wp file-cleanup preview --files    # avec la liste des fichiers
wp file-cleanup run                # un aperçu, parce que c'est le défaut sûr
wp file-cleanup run --execute      # pour de vrai
```

Deux exécutions ne peuvent pas se chevaucher : un verrou est pris pour la durée.

## Les règles prêtes à l'emploi

Les dossiers par défaut d'extensions connues pour laisser des fichiers derrière
elles — PDF Invoices & Packing Slips, YITH Request a Quote, WP Staging —
s'ajoutent comme règle en un clic. Elles arrivent **désactivées**, avec le
dossier modifiable, et les deux qui contiennent des pièces comptables arrivent
**sans aucune rétention**, pour qu'elles ne puissent pas tourner avant que
quelqu'un ait décidé combien de temps une facture est conservée.

Ajouter les vôtres :

```php
add_filter( 'dahu_file_cleanup_presets', function ( $presets ) {
	$presets['my_exports'] = array(
		'label'      => 'Exports nocturnes',
		'paths'      => 'wp-content/uploads/my-exports',
		'extensions' => array( 'csv', 'zip' ),
		'days'       => 14,
		'note'       => 'Écrits par la tâche d\'export nocturne.',
	);

	return $presets;
} );
```

## Les points d'accroche

```php
// Conserver un fichier. Appelé pour chaque fichier sélectionné lors d'une
// vraie exécution — jamais pendant l'aperçu, donc un filtre qui conserve des
// fichiers rend l'aperçu optimiste.
add_filter( 'dahu_file_cleanup_delete_file', function ( $delete, $file, $rule_key, $rule ) {
	return $delete;
}, 10, 4 );

// Après une exécution, programmée ou manuelle.
add_action( 'dahu_file_cleanup_after_run', function ( $summary, $mode ) {}, 10, 2 );
```

## Le journal

Une ligne par règle et par exécution, la plus récente d'abord, deux cents lignes
conservées, dans une option qui n'est **pas** autochargée. Des comptes, pas des
noms de fichiers : la liste de chaque fichier supprimé serait le journal le plus
utile du monde et aussi une option qui grossit sans limite sur un site qui
supprime des milliers de fichiers par nuit, lue à chaque requête. La
comptabilité fichier par fichier, c'est à quoi sert
`dahu_file_cleanup_delete_file`.

## À la désinstallation

**Aucun fichier du disque n'est jamais supprimé par la routine de
désinstallation.** C'est une extension dont le travail est de supprimer des
fichiers, et un dernier passage sur les règles en partant — sans surveillance,
au moment où quelqu'un a décidé qu'il n'en voulait plus — serait la chose la
plus destructrice qu'elle contienne.

Les règles et le journal ne partent que si vous l'avez demandé sur l'écran, et le
réglage est désactivé par défaut.

## Services externes

Cette extension ne contacte aucun service externe.

## Licence

MIT — voir [LICENSE](LICENSE).
