<?php

return [
    'forbidden' => [
        'title' => 'Not allowed',
        'heading' => 'This page is for admins only',
        'message' => 'Your account can only search the stock. If you need more access, ask an admin.',
        'back' => 'Back to apps',
    ],
    'server' => [
        'title' => 'Error',
        'heading' => 'Something went wrong',
        'message' => 'The error was logged for the developer. Try again in a moment; if it keeps happening, tell an admin.',
        'retry' => 'Try again',
        'home' => 'Home',
    ],
    'maintenance' => [
        'title' => 'Short update',
        'heading' => 'Short system update',
        'message' => 'We will be back in a moment. You don\'t need to do anything: the page reloads by itself.',
        'retry_now' => 'Try again now',
        'retry_in' => 'Trying again in :seconds seconds…',
    ],
];
