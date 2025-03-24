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
        'navigation'        => 'system/navigation',
        'settings'          => 'system/settings',
        'login'             => 'login/login',
        'logout'            => 'login/logout',
        'pages'             => 'pages/pages',
        'page-edit'         => 'pages/page-edit',
        'page-versions'     => 'pages/page-versions',
        'blog'              => 'blog/blog',
        'blog-edit'         => 'blog/blog-edit',
        'blog-categories'   => 'blog/blog-categories',
        'blog-tags'         => 'blog/blog-tags',
        'blog-versions'     => 'blog/blog-versions',
        'media'             => 'media/media',
        'users'             => 'user/users',
        'user-edit'         => 'user/user-edit',
        'user-add'          => 'user/user-add',
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
