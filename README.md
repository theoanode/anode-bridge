# anode-bridge

> **Miroir généré — ne pas modifier ici.**
>
> La source vit dans le dépôt `wordpress`, sous
> `plugin/anode-bridge`.
> Ce dépôt est régénéré à chaque publication par
> `bin/release-mu-plugin.mjs`. Une modification faite ici serait écrasée.

## À quoi il sert

Il porte les **releases** que `anode-updater` installe sur les sites.
Chaque release publie :

```
<composant>-<version>.zip           le dossier du composant, tests exclus
<composant>-<version>.zip.sha256    son empreinte, vérifiée avant installation
<composant>-<version>.zip.sig       sa signature Ed25519, si le parc est armé
```

Les tags sont de la forme `v1.2.0` : ce dépôt n’héberge qu’un composant.

## Installation manuelle

Elle ne devrait pas être nécessaire — `anode-updater` s’en charge. Au besoin,
décompresser l’archive dans `wp-content/mu-plugins/`.


