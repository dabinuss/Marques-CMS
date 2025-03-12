<article class="super-blog-post super-blog-single">
  <header class="post-header">
    <h1 class="post-title"><?= htmlspecialchars($post['title']); ?></h1>
    <div class="post-meta">
      <span><?= marques_format_date($post['date'], 'd.m.Y'); ?></span> –
      <span>von <?= htmlspecialchars($post['author']); ?></span>
    </div>
  </header>
  
  <?php if (!empty($post['featured_image'])): ?>
  <div class="post-featured-image">
    <img src="<?= marques_site_url($post['featured_image']); ?>" alt="<?= htmlspecialchars($post['title']); ?>">
  </div>
  <?php endif; ?>
  
  <div class="post-content">
    <?= $post['content']; ?>
  </div>
  
  <?php if (!empty($post['tags'])): ?>
  <div class="post-tags">
    <span class="tags-title">Tags:</span>
    <?php 
      $tagLinks = [];
      foreach ($post['tags'] as $tag) {
        if (!empty($tag)) {
          $tagLinks[] = '<a href="' . marques_site_url('blog?tag=' . urlencode($tag)) . '" class="tag-link">' . htmlspecialchars($tag) . '</a>';
        }
      }
      echo implode(' ', $tagLinks);
    ?>
  </div>
  <?php endif; ?>
  
  <div class="post-navigation">
    <a href="<?= marques_site_url('blog'); ?>" class="back-to-blog">&laquo; Zurück zum Blog</a>
  </div>
</article>
