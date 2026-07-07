# Boublil Resource Access

Plugin WordPress privé pour protéger le téléchargement de ressources par saisie d'une adresse email valide.

## Fonctionnalités

- Shortcode principal : `[boublil_resource_gate id="resource-id"]`
- Compatibilité avec l'ancien shortcode : `[bc_report_gate title="Titre de la ressource"]`
- Validation email côté navigateur et côté serveur
- Lien de téléchargement affiché après validation de l'email
- Lien également envoyé par email via `wp_mail()`
- Journalisation des contacts et des demandes dans des tables dédiées
- Page admin WordPress pour les réglages, les ressources, la visualisation des données et l'export CSV

## Installation

1. Copier le dossier `boublil-resource-access` dans `wp-content/plugins/`.
2. Activer le plugin dans l'administration WordPress.
3. Aller dans `Ressources` pour ajuster les réglages email et les ressources.

## Auteur

Eli Gold  
GitHub : https://github.com/elig45
