<?php
declare(strict_types=1);

namespace Admin\Controller;

use Marques\Service\PageManager;
use Marques\Util\Helper;
use Marques\Service\VersionManager;
use Marques\Filesystem\FileManager;
use Marques\Filesystem\PathRegistry;

use Admin\Core\Template;

class PageController
{
    private Template $adminTemplate;
    private PageManager $pageManager;
    private Helper $helper;
    private FileManager   $fileManager;
    private PathRegistry  $pathRegistry;

    public function __construct(
        Template $adminTemplate, 
        PageManager $pageManager, 
        Helper $helper,
        FileManager $fileManager,
        PathRegistry $pathRegistry
    ) {
        $this->adminTemplate = $adminTemplate;
        $this->pageManager = $pageManager;
        $this->helper = $helper;
        $this->fileManager = $fileManager->useDirectory('content');;
        $this->pathRegistry = $pathRegistry;
    }

    /**
     * Erstellt oder aktualisiert eine Seite
     *
     * @param array $pageData Seiten-Daten
     * @return bool True bei Erfolg
     */
    public function savePage(array $pageData): bool
    {
        if (empty($pageData['id'])) {
            if (empty($pageData['title'])) {
                return false;
            }
            $pageData['id'] = $this->pageManager->generateSlug($pageData['title']);
        }

        if (!$this->isValidFileId($pageData['id'])) {
            return false;
        }

        $relativePath = 'pages/' . $pageData['id'] . '.md';
        $isUpdate     = $this->fileManager->exists($relativePath);

        if ($isUpdate) {
            $versionManager  = new VersionManager();
            $currentUsername = $_SESSION['marques_user']['username'] ?? 'system';
            $versionManager->createVersion(
                'pages',
                $pageData['id'],
                $this->fileManager->readFile($relativePath) ?? '',
                $currentUsername
            );
        }

        $frontmatter = [
            'title'         => $pageData['title']         ?? '',
            'description'   => $pageData['description']   ?? '',
            'template'      => $pageData['template']      ?? 'page',
            'featured_image'=> $pageData['featured_image']?? '',
            'date_created'  => $isUpdate
                ? ($this->pageManager->getPage($pageData['id'])['date_created'] ?? date('Y-m-d'))
                : date('Y-m-d'),
            'date_modified' => date('Y-m-d'),
        ];

        $yamlContent = '';
        foreach ($frontmatter as $key => $value) {
            $yamlContent .= $key . ': "' . str_replace('"', '\"', (string) $value) . "\"\n";
        }

        $content = "---\n" . $yamlContent . "---\n\n" . ($pageData['content'] ?? '');

        return $this->fileManager->writeFile($relativePath, $content);
    }
    
    /**
     * Löscht eine Seite
     *
     * @param string $id Seiten-ID
     * @return bool True bei Erfolg
     */
    public function deletePage(string $id): bool
    {
        if (!$this->isValidFileId($id)) {
            return false;
        }

        $relativePath = 'pages/' . $id . '.md';
        if (!$this->fileManager->exists($relativePath)) {
            return false;
        }

        $backupDir       = 'versions/pages';
        $timestampedName = $id . '_' . date('YmdHis') . '.md';
        $this->fileManager->createDirectory($backupDir);

        $backupRelative = $backupDir . '/' . $timestampedName;
        $originalData   = $this->fileManager->readFile($relativePath) ?? '';
        $this->fileManager->writeFile($backupRelative, $originalData);

        return $this->fileManager->deleteFile($relativePath);
    }

    /**
     * Sicherheitsüberprüfung für Datei-IDs
     */
    private function isValidFileId(string $id): bool {
        return preg_match('/^[a-z0-9_-]+$/i', $id) && 
               strpos($id, '/') === false && 
               strpos($id, '\\') === false;
    }

}