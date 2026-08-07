# anode-bridge

Dépôt de distribution. Le contenu est régénéré à la publication ;
toute retouche faite ici est perdue au passage suivant.

## Contenu d’une release

| Fichier | Rôle |
|---|---|
| `.zip` | l’extension, sans ses tests |
| `.zip.sha256` | empreinte, contrôlée avant d’écrire sur le disque |
| `.zip.sig` | signature, contrôlée quand la clé publique est en place |

## Étiquettes

Une seule extension ici : l’étiquette ne porte que le numéro.

## Pose à la main

Le site le fait seul. En dépannage, l’archive se décompresse dans
`wp-content/mu-plugins/`.

