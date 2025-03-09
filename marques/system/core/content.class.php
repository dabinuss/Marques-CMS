<?php
declare(strict_types=1);

namespace Marques\Core;

class Content extends Core {
    private $_cache = [];
    private $_configManager;

    public function __construct(Docker $docker) {
        parent::__construct($docker);
        $this->_configManager = $this->resolve('config');
    }

    public function getPage(string $path): array {
        if (empty($path)) {
            throw new \InvalidArgumentException("Ungültiger Seitenpfad");
        }

        if (isset($this->_cache[$path])) {
            return $this->_cache[$path];
        }

        $params = $GLOBALS['route']['params'] ?? [];

        // Blog-Logik
        if (strpos($path, 'blog') === 0) {
            if ($path === 'blog') {
                // Blog-Übersichtsseite (alle Beiträge)
                return $this->getBlogList($path, $params);
            } elseif (preg_match('#^blog/(?P<slug>[a-zA-Z0-9-]+)$#', $path, $matches)) {
                // Einzelner Blog-Post
				return $this->getBlogPost($path, ['slug' => $matches['slug']]);
            } else {
                // z.B. blog-category, blog-archive, etc.
                return $this->getBlogList($path, $params);
            }
        }

        // Reguläre Seite
        $filePath = MARQUES_CONTENT_DIR . '/pages/' . $path . '.md';
        if (!file_exists($filePath) || !is_readable($filePath)) {
			return [ //Rückgabe statt exception
                'template' => 'page',
                'title' => 'Error',
                'content' => '<h1>404 Not Found</h1>',
            ];
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new \Exception("Fehler beim Lesen der Datei: " . $path);
            }
            $pageData = $this->parseContentFile($content);
        } catch (\Exception $e) {
            throw new \Exception("Fehler beim Parsen der Inhalte: " . $e->getMessage());
        }

        $pageData['template'] = $pageData['template'] ?? 'page';
        $pageData['path'] = $path;
        $this->_cache[$path] = $pageData;
        return $pageData;
    }



	private function getBlogPost(string $path, array $params): array
	{
		$blogManager = $this->resolve('blog_manager');
		$post = $blogManager->getPostBySlug($params['slug'] ?? '');

		if (!$post) {
			return [ //Rückgabe statt exception
                'template' => 'page',
                'title' => 'Error',
                'content' => '<h1>404 Not Found</h1>',
            ];
		}

		return [
			'title' => $post['title'],
			'content' => $post['content'],
			'description' => $post['excerpt'] ?? '',
			'date_created' => $post['date_created'] ?? $post['date'] ?? '',
			'date_modified' => $post['date_modified'] ?? $post['date'] ?? '',
			'template' => 'blog_post',
			'path' => $path,
			'params' => $params,
			'post' => $post,
			'blogManager' => $blogManager
		];
	}

    private function getBlogList(string $path, array $params): array
    {
        $query = $GLOBALS['route']['query'] ?? [];

        $title = 'Blog';
        $description = 'Alle Blog-Beiträge';

        // Kategorie-Filter (Beispiel)
        if ($path === 'blog-category' && isset($params['category'])) {
            $title = 'Blog - Kategorie: ' . htmlspecialchars($params['category']);
            $description = 'Blog-Beiträge in der Kategorie ' . htmlspecialchars($params['category']);
        }

        // Weitere Filter (Archiv, etc.) hier hinzufügen

        $blogManager = $this->resolve('blog_manager'); // Inject BlogManager
        return [
            'title' => $title,
            'content' => '', // Wird vom Template gefüllt (blog-list.tpl.php)
            'description' => $description,
            'template' => 'blog_list', // Oder ein anderes Template, je nach Bedarf
            'path' => $path,
            'params' => $params,
            'query' => $query,
            'blogManager' => $blogManager, // Inject BlogManager
        ];
    }

    private function parseContentFile($content) {
        // Frontmatter und Inhalt aufteilen
        $parts = preg_split('/[\r\n]*---[\r\n]+/', $content, 3);

        // Frontmatter und Inhalt extrahieren
        $frontmatter = '';
        $body = '';

        if (count($parts) === 3) {
            // Datei hat Frontmatter
            $frontmatter = $parts[1];
            $body = $parts[2];
        } else {
            // Kein Frontmatter
            $body = $content;
        }

        // Frontmatter parsen (YAML)
        $data = [];
        if (!empty($frontmatter)) {
            $data = $this->parseYaml($frontmatter);
        }

        // Inhalt zu Daten hinzufügen
        $data['content'] = $this->parseMarkdown($body);
        $data['content_raw'] = $body;

        return $data;
    }
    private function parseYaml($yaml) {
        // Einfacher YAML-Parser für Frontmatter
        $lines = explode("\n", $yaml);
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                if (preg_match('/^[\'"](.*)[\'""]$/', $value, $stringMatches)) {
                    $value = $stringMatches[1];
                }
                if (preg_match('/^\[([^]]*)\]$/', $value, $arrayMatches)) {
                    $arrayString = $arrayMatches[1];
                    $arrayItems = explode(',', $arrayString);
                    $value = array_map('trim', $arrayItems);
                }

                $data[$key] = $value;
            }
        }

        return $data;
    }
    private function parseMarkdown($markdown) {
        $html = $markdown;

        // Code-Blöcke vor der Verarbeitung schützen
        $codeBlocks = [];
        $html = preg_replace_callback('/```(.+?)```/s', function($matches) use (&$codeBlocks) {
            $placeholder = '___CODE_BLOCK_' . count($codeBlocks) . '___';
            $codeBlocks[] = $matches[1];
            return $placeholder;
        }, $html);
        $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^#### (.*?)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^##### (.*?)$/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^###### (.*?)$/m', '<h6>$1</h6>', $html);
        $html = preg_replace('/^(\*|\-|\+) (.*?)$/m', '<li>$2</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ul>$0</ul>', $html);

        $html = preg_replace('/^[0-9]+\. (.*?)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*?<\/li>\n)+/s', '<ol>$0</ol>', $html);
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
        $html = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $html);
        $html = preg_replace('/^(\-{3,}|\*{3,}|_{3,})$/m', '<hr>', $html);
        $html = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $html);
        $html = preg_replace('/`(.*?)`/s', '<code>$1</code>', $html);
        $html = preg_replace('/(?<!<\/h[1-6]>|<\/li>|<hr>)\n\n(?!<h[1-6]|<ul|<ol|<li|<hr>)/', '</p><p>', $html);
        if (!preg_match('/^<[ho]/', $html)) {
            $html = '<p>' . $html;
        }
        if (!preg_match('/<\/[^>]+>$/', $html)) {
            $html .= '</p>';
        }
        $html = preg_replace_callback('/___CODE_BLOCK_(\d+)___/', function($matches) use ($codeBlocks) {
            $index = (int)$matches[1];
            return '<pre><code>' . htmlspecialchars($codeBlocks[$index]) . '</code></pre>';
        }, $html);

        return $html;
    }
}