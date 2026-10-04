<?php
declare(strict_types=1);
$contentType = $contextType;
$section = cmsContentSection($contentType);
?>
<section class="panel narrow">
    <p class="eyebrow">New English <?= $section['singular'] ?></p>
    <h2>Start with a draft</h2>
    <p class="muted">Create your <?= $section['singular'] ?>, add content in the visual editor, and preview it before publishing.</p>
    <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="create_page">
        <input type="hidden" name="content_type" value="<?= $contentType ?>">
        <label><?= $section['title'] ?> title<input name="title" required maxlength="490" placeholder="Enter a title" id="new-page-title"></label>
        <label><?= $section['title'] ?> path<input name="route" required placeholder="<?= $section['prefix'] ?>your-title/" pattern="/(?:[a-zA-Z0-9_-]+/)*" id="new-page-route" data-prefix="<?= $section['prefix'] ?>"></label>
        <button>Create draft</button>
    </form>
</section>
