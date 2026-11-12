<?php

// Les exercices de Gescomm (App\Models\Exercise) : le menu "السنة المالية", sa banniere, la page des branches
return [
    'title' => 'Financial year',
    'current' => 'Current year',
    'current_help' => 'what the agent reads in Gescomm now',
    'closed' => 'Closed years',
    'banner' => 'You are viewing the closed year “:name” of :branch (:from → :to). Every page shows it, as if you opened its file in Gescomm; “last 30 days” and the other periods count back from its last day.',
    'back' => 'Back to the current year',
    'pill' => 'Year :name',
    'balance_closed' => 'at the closing of year :name',
    'counts' => ':sales sales bons, :purchases purchase bons, :payments payments',
    'waiting' => 'not fully arrived yet',
    'synced' => 'fully arrived :time',
    'none' => 'No closed year yet. Agent 1.0.11 sends the archives on its own.',
];
