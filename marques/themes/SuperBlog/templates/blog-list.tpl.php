<div class="blog-grid">
  <?php if (empty($posts)): ?>
    <p>Keine Blog-Beiträge gefunden.</p>
  <?php else: ?>
    <?php foreach ($posts as $post): ?>
      <article class="super-blog-post">
        <?php if (!empty($post['featured_image'])): ?>
          <a href="<?= marques_format_blog_url($post); ?>">
            <img src="<?= marques_site_url($post['featured_image']); ?>" alt="<?= htmlspecialchars($post['title']); ?>">
          </a>
        <?php endif; ?>
        <div class="post-content">
          <h2 class="post-title">
            <a href="<?= marques_format_blog_url($post); ?>">
              <?= htmlspecialchars($post['title']); ?>
            </a>
          </h2>
          <div class="post-meta">
            <span><?= marques_format_date($post['date'], 'd.m.Y'); ?></span> –
            <span>von <?= htmlspecialchars($post['author']); ?></span>
          </div>
          <p class="post-excerpt"><?= htmlspecialchars($post['excerpt']); ?></p>
          <a class="read-more" href="<?= marques_format_blog_url($post); ?>">Weiterlesen</a>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Paginierung -->
<div class="super-blog-pagination">
  <?php if ($page > 1): ?>
    <a href="<?= marques_site_url('blog?page=' . ($page - 1)); ?>">&laquo; Vorherige</a>
  <?php endif; ?>
  <?php if (count($posts) === $perPage): ?>
    <a href="<?= marques_site_url('blog?page=' . ($page + 1)); ?>">Nächste &raquo;</a>
  <?php endif; ?>
</div>
