<?php
/**
 * marques CMS - Routen-Konfiguration
 * 
 * Definiert die verfügbaren Routen.
 *
 * @package marques
 * @subpackage config
 */

// Direkten Zugriff verhindern
if (!defined('MARCES_ROOT_DIR')) {
    exit('Direkter Zugriff ist nicht erlaubt.');
}

return [
    // Spezifische Pfade
    'paths' => [
        'home' => [
            'template' => 'page'
        ],
        'blog-index' => [
            'template' => 'blog-list'
        ]
    ],
    
    // Routen-Muster
    'patterns' => [
        'blog' => [
            'pattern' => 'blog/(\d{4})/(\d{2})/(\d{2})/(.+)',
            'template' => 'blog-post'
        ],
        'blog-category' => [
            'pattern' => 'blog/category/(.+)',
            'template' => 'blog-list'
        ],
        'blog-archive' => [
            'pattern' => 'blog/(\d{4})/(\d{2})',
            'template' => 'blog-list'
        ]
    ]
];