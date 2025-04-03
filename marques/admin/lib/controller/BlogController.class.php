<?php
declare(strict_types=1);

namespace Marques\Admin\Controller;

use Marques\Data\Database\Handler as DatabaseHandler;
use Marques\Admin\AdminTemplate;
use Marques\Service\BlogManager;
use Marques\Util\Helper;

class BlogController
{

    public function __construct(
        AdminTemplate $adminTemplate, 
        BlogManager $blogManager,
        Helper $helper
    ) {
        $this->template = $adminTemplate;
        $this->blogManager = $blogManager;
        $this->helper = $helper;
    }
}