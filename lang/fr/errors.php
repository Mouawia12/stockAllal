<?php

return [
    'forbidden' => [
        'title' => 'Accès refusé',
        'heading' => 'Cette page est réservée à l\'administrateur',
        'message' => 'Votre compte permet seulement la recherche dans le stock. Si vous avez besoin de plus de droits, demandez-les à l\'administrateur.',
        'back' => 'Retour aux applications',
    ],
    'server' => [
        'title' => 'Erreur',
        'heading' => 'Une erreur inattendue s\'est produite',
        'message' => 'L\'erreur a été enregistrée pour le développeur. Réessayez dans un instant ; si elle se répète, prévenez l\'administrateur.',
        'retry' => 'Réessayer',
        'home' => 'Accueil',
    ],
    'maintenance' => [
        'title' => 'Courte mise à jour',
        'heading' => 'Courte mise à jour du système',
        'message' => 'Nous revenons dans quelques instants. Vous n\'avez rien à faire : la page se recharge toute seule.',
        'retry_now' => 'Réessayer maintenant',
        'retry_in' => 'Nouvel essai dans :seconds secondes…',
    ],
];
