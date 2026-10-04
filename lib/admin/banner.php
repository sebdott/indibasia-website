<?php declare(strict_types=1); ?>
<section class="panel banner-panel" aria-labelledby="banner-panel-title">
    <h2 id="banner-panel-title">Banner header</h2>
    <p class="muted">Set the featured image and wording shown at the top of this <?= cmsEscape($section['singular']) ?>.</p>
    <?php $bannerNumber = 0; foreach ($banners as $bannerKey => $banner): $bannerNumber++; ?>
    <div class="banner-fields">
        <?php if (count($banners) > 1): ?><h3>Banner <?= $bannerNumber ?> (responsive version)</h3><?php endif ?>
        <div class="image-edit banner-image-edit">
            <div class="banner-image-preview" data-image-kind="<?= cmsEscape($banner['kind']) ?>"><img <?= $banner['image'] !== '' ? 'src="' . cmsEscape($banner['image']) . '"' : '' ?> alt="Featured image preview" <?= $banner['image'] === '' ? 'hidden' : '' ?>><span role="status" <?= $banner['image'] !== '' ? 'hidden' : '' ?>>No featured image</span></div>
            <div><label>Featured image URL<input id="banner-image-<?= $bannerKey ?>" name="banner[<?= $bannerKey ?>][image]" value="<?= cmsEscape($banner['image']) ?>" data-banner-image></label>
            <div class="banner-image-actions"><button type="button" class="secondary choose-image" data-target="banner-image-<?= $bannerKey ?>">Choose featured image</button><button type="button" class="text-button" data-clear-image="banner-image-<?= $bannerKey ?>">Remove image</button></div>
            <?php if ($banner['video']): ?><p class="field-help">This banner uses a video. Choosing a featured image replaces the video background when saved.</p><?php else: ?><p class="field-help">Choose from the media library or paste an HTTPS image URL.</p><?php endif ?></div>
        </div>
        <?php $hasTitle = false; $hasDescription = false; foreach ($banner['fields'] as $fieldKey => $field):
            $hasTitle = $hasTitle || $field['label'] === 'Banner title'; $hasDescription = $hasDescription || $field['label'] === 'Banner description'; ?>
            <label><?= cmsEscape($field['label']) ?><textarea name="banner[<?= $bannerKey ?>][text][<?= $fieldKey ?>]" rows="<?= strlen($field['value']) > 100 ? 3 : 1 ?>" maxlength="5000"><?= cmsEscape($field['value']) ?></textarea></label>
        <?php endforeach ?>
        <?php if (!$hasTitle): ?><label>Banner title<input name="banner[<?= $bannerKey ?>][title]" maxlength="5000" placeholder="Add a banner title"></label><?php endif ?>
        <?php if (!$hasDescription): ?><label>Banner description<textarea name="banner[<?= $bannerKey ?>][description]" rows="3" maxlength="5000" placeholder="Add a short introduction"></textarea></label><?php endif ?>
    </div>
    <?php endforeach ?>
    <?php if (!$banners): ?>
        <p class="field-help">Add a banner by choosing an image or entering wording below.</p>
        <div class="image-edit banner-image-edit"><div class="banner-image-preview"><img alt="Featured image preview" hidden><span role="status">No featured image</span></div><div><label>Featured image URL<input id="banner-image-new" name="banner_new[image]" data-banner-image></label><div class="banner-image-actions"><button type="button" class="secondary choose-image" data-target="banner-image-new">Choose featured image</button><button type="button" class="text-button" data-clear-image="banner-image-new">Remove image</button></div></div></div>
        <label>Banner title<input name="banner_new[title]" maxlength="5000" placeholder="Add a banner title"></label>
        <label>Banner description<textarea name="banner_new[description]" rows="3" maxlength="5000" placeholder="Add a short introduction"></textarea></label>
    <?php endif ?>
</section>
