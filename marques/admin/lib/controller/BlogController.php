<?php
declare(strict_types=1);

namespace Admin\Controller;

use Marques\Data\Database\Handler as DatabaseHandler;
use Admin\Core\Template;
use Marques\Service\BlogManager;
use Marques\Util\Helper;

class BlogController
{

    public function __construct(
        Template $adminTemplate, 
        BlogManager $blogManager,
        Helper $helper
    ) {
        $this->template = $adminTemplate;
        $this->blogManager = $blogManager;
        $this->helper = $helper;
    }
}