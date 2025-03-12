<div class="blog-grid">
  <?php if (empty($posts)): ?>
    <p>Keine Blog-Beiträge gefunden.</p>
  <?php else: ?>
    <?php foreach ($posts as $post): ?>
      <article class="super-blog-post">
        <?php if (!empty($post['featured_image'])): ?>
          <a href="<?= \Marques\Core\Helper::generateBlogUrl($post); ?>">
            <img src="<?= \Marques\Core\Helper::getSiteUrl($post['featured_image']); ?>" alt="<?= htmlspecialchars($post['title']); ?>">
          </a>
        <?php endif; ?>
        <div class="post-content">
          <h2 class="post-title">
            <a href="<?= \Marques\Core\Helper::generateBlogUrl($post); ?>">
              <?= htmlspecialchars($post['title']); ?>
            </a>
          </h2>
          <div class="post-meta">
            <span><?= \Marques\Core\Helper::formatDate($post['date'], 'd.m.Y'); ?></span> –
            <span>von <?= htmlspecialchars($post['author']); ?></span>
          </div>
          <p class="post-excerpt"><?= htmlspecialchars($post['excerpt']); ?></p>
          <a class="read-more" href="<?= \Marques\Core\Helper::generateBlogUrl($post); ?>">Weiterlesen</a>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Paginierung -->
<div class="super-blog-pagination">
  <?php if ($page > 1): ?>
    <a href="<?= \Marques\Core\Helper::getSiteUrl('blog?page=' . ($page - 1)); ?>">&laquo; Vorherige</a>
  <?php endif; ?>
  <?php if (count($posts) === $perPage): ?>
    <a href="<?= \Marques\Core\Helper::getSiteUrl('blog?page=' . ($page + 1)); ?>">Nächste &raquo;</a>
  <?php endif; ?>
</div>
