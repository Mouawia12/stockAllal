<?php

return [
    'title' => 'Succursales',
    'download_agent' => 'Télécharger l\'agent',
    'guide' => 'Guide d\'installation PDF',
    'new_code' => 'Nouveau code d\'installation',
    'code_once' => 'Code d\'installation — à usage unique, valable 24 heures. Il ne sera plus affiché :',
    'code_help' => 'Sur le serveur Gescomm de la nouvelle succursale : décompressez « Télécharger l\'agent », lancez :script en administrateur, puis saisissez le nom de la succursale et ce code.',
    'api_url' => 'Adresse de l\'API : :url — détails dans le « Guide d\'installation ».',
    'empty' => 'Aucune succursale pour l\'instant. Téléchargez l\'agent, créez un code d\'installation, puis installez l\'agent sur le serveur de la succursale (voir le « Guide d\'installation »).',

    'col_name' => 'Nom',
    'col_status' => 'État',
    'col_last_sync' => 'Dernière synchro',
    'col_items' => 'Articles',
    'col_db_write' => 'Dernière écriture de la base',
    'col_host' => 'Machine',

    'main' => 'Principale',
    'main_pill_tip' => 'Ses prix sont ceux affichés dans la recherche',
    'disabled' => 'Désactivée',
    'online' => 'En ligne',
    'offline' => 'Hors ligne',
    'never' => 'Jamais',
    'sync_now' => 'Synchroniser',
    'sync_tip' => 'Demande à l\'agent de la succursale d\'envoyer tout le stock maintenant',
    'main_tip' => 'Les prix de la succursale principale s\'affichent dans la recherche, et l\'aperçu signale les succursales dont le prix diffère',
    'unset_main' => 'Retirer « principale »',
    'set_main' => 'Définir comme principale',
    'disable' => 'Désactiver',
    'enable' => 'Activer',
    'confirm_delete' => 'Supprimer la succursale « :name » et tout son stock ? Son agent sera ensuite refusé.',
    'help' => [
        '« Synchroniser » = demande à l\'agent d\'envoyer tout le stock tout de suite, sans attendre la synchronisation automatique (toutes les 5 minutes). Nécessite l\'agent 1.0.4 ou plus récent.',
        '« Dernière écriture de la base » = dernière modification du fichier de base Gescomm de la succursale. Si cela dépasse une journée entière un jour ouvré, le logiciel travaille peut-être sur un autre fichier (après une restauration) — vérifiez le chemin de la base dans la configuration de l\'agent.',
        'L\'agent d\'une succursale désactivée est refusé et elle n\'apparaît pas dans la recherche.',
        '« Succursale principale » = référence des prix : son prix s\'affiche sur la ligne de chaque article dans la recherche, et l\'aperçu montre le prix de chaque succursale en signalant ceux qui diffèrent. Nécessite l\'agent 1.0.5 ou plus récent (prix).',
    ],

    'sync' => [
        'pending' => 'En attente de l\'agent…',
        'taken' => 'L\'agent envoie le stock…',
        'done' => 'Synchronisé ✓',
        'error' => 'La synchronisation a échoué dans la succursale',
        'timeout' => 'L\'agent n\'a pas répondu — la succursale est-elle en ligne ?',
    ],

    'name_required' => 'Le nom de la succursale est obligatoire',
    'now_main' => '« :name » est maintenant la succursale principale : son prix s\'affiche dans la recherche',
    'no_longer_main' => '« :name » n\'est plus la succursale principale',
    'deleted' => 'Succursale « :name » et son stock supprimés',
    'is_disabled' => 'La succursale est désactivée',
];
