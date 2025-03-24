<?php
declare(strict_types=1);

namespace Marques\Admin;

class AdminRouter
{
    /**
     * Definiert erlaubte Seiten mit Unterpfaden
     *
     * @var array
     */
    protected array $allowedPages = [
        'dashboard'         => 'system/dashboard',
        'login'             => 'login/login',
        'logout'            => 'login/logout',
        'settings'          => 'system/settings',
        'pages'             => 'pages/pages',
        'page-add'          => 'pages/page-add',
        'page-edit'         => 'pages/page-edit',
        'page-versions'     => 'pages/page-versions',
        'navigation'        => 'system/navigation',
        'navigation-add'    => 'system/navigation-add',
        'navigation-edit'   => 'system/navigation-edit',
        'users'             => 'user/users',
        'user-edit'         => 'user/user-edit',
        'user-add'          => 'user/user-add',
        'media'             => 'media/media',
        'blog'              => 'blog/blog',
        'blog-edit'         => 'blog/blog-edit',
        'blog-categories'   => 'blog/blog-categories',
        'blog-tags'         => 'blog/blog-tags',
        'blog-versions'     => 'blog/blog-versions',
    ];

    /**
     * Ermittelt die aktuell angeforderte Admin-Seite
     *
     * @return string Schlüssel der Seite
     */
    public function route(): string
    {
        $page = $_GET['page'] ?? 'dashboard';
        if (!array_key_exists($page, $this->allowedPages)) {
            throw new \Exception("Seite '{$page}' ist nicht erlaubt.");
        }
        return $this->allowedPages[$page];
    }
}
