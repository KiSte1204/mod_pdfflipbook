<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Shared markup for the "List" and "Grid" layouts.
 *
 * @var \Joomla\Registry\Registry $params
 * @var object[]                  $items
 * @var string                    $variant
 * @var string                    $styleAttr
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTMLHelper\HTMLHelper;
use Joomla\CMS\Language\Text;

$e            = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$target       = $params->get('target', '_blank') === '_self' ? '_self' : '_blank';
$relAttr      = $target === '_blank' ? ' rel="noopener"' : '';
$intro        = '';

if ($params->get('show_intro', 1)) {
    // Eigener Text aus dem Modul, sonst der Standardtext der jeweiligen Sprache
    $intro = trim((string) $params->get('intro', ''));

    if ($intro === '' && Text::_('MOD_PDFFLIPBOOK_INTRO') !== 'MOD_PDFFLIPBOOK_INTRO') {
        $intro = '<p><strong>' . $e(Text::_('MOD_PDFFLIPBOOK_INTRO')) . '</strong></p>';
    }
}
$showDownload = (bool) $params->get('show_download', 0);
$suffix       = trim((string) $params->get('moduleclass_sfx', ''));
?>
<div class="mod-pdfflipbook mod-pdfflipbook--<?php echo $variant; ?><?php echo $suffix !== '' ? ' ' . $e($suffix) : ''; ?>"<?php echo $styleAttr; ?>>
    <?php if ($intro !== '') : ?>
        <div class="mod-pdfflipbook__intro"><?php echo $intro; ?></div>
    <?php endif; ?>

    <ul class="mod-pdfflipbook__items">
        <?php foreach ($items as $item) : ?>
            <li class="mod-pdfflipbook__item" lang="<?php echo $e($item->lang); ?>">
                <a class="mod-pdfflipbook__link" href="<?php echo $e($item->viewer); ?>" target="<?php echo $target; ?>"<?php echo $relAttr; ?>>
                    <span class="mod-pdfflipbook__title"><?php echo $e($item->title); ?></span>
                    <?php if (!empty($item->description)) : ?>
                        <span class="mod-pdfflipbook__desc"><?php echo nl2br($e($item->description)); ?></span>
                    <?php endif; ?>
                    <?php if ($item->thumb) : ?>
                        <img class="mod-pdfflipbook__thumb"
                             src="<?php echo $e($item->thumb); ?>"
                             width="<?php echo (int) $item->thumbWidth; ?>"
                             <?php if ($item->thumbHeight) : ?>height="<?php echo (int) $item->thumbHeight; ?>"<?php endif; ?>
                             style="width:<?php echo (int) $item->thumbWidth; ?>px"
                             alt="" loading="lazy" decoding="async">
                    <?php else : ?>
                        <span class="mod-pdfflipbook__nothumb" style="width:<?php echo (int) $item->thumbWidth; ?>px" aria-hidden="true">PDF</span>
                    <?php endif; ?>
                </a>
                <?php if ($showDownload) : ?>
                    <a class="mod-pdfflipbook__download" href="<?php echo $e($item->pdf); ?>" download>
                        <?php echo Text::_('MOD_PDFFLIPBOOK_DOWNLOAD'); ?>
                        <?php if ($item->size) : ?>
                            <span class="mod-pdfflipbook__size">(<?php echo number_format($item->size / 1048576, 1, $item->pageLang === 'en' ? '.' : ',', '') . ' MB'; ?>)</span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
