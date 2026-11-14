<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Layout "Downloads": text list of all PDFs, grouped by language or folder,
 * with size, date, "Browse" and "Download". Groups can be collapsible.
 *
 * @var \Joomla\Registry\Registry $params
 * @var object[]                  $items
 * @var string                    $notice
 * @var string                    $styleAttr
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTMLHelper\HTMLHelper;
use Joomla\CMS\Language\Text;
use Webkiste\Module\PdfFlipbook\Site\Helper\FlipbookHelper;

// Setup hints (only visible to administrators)
if (!empty($notice)) {
    echo '<div class="alert alert-warning mod-pdfflipbook__notice">' . $notice . '</div>';
}

if (empty($items)) {
    return;
}

$e       = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$target  = $params->get('target', '_blank') === '_self' ? '_self' : '_blank';
$relAttr = $target === '_blank' ? ' rel="noopener"' : '';
$suffix  = trim((string) $params->get('moduleclass_sfx', ''));
$intro   = $params->get('show_intro', 1) ? trim((string) $params->get('intro', '')) : '';

// Group by language or folder (the order already comes sorted from the helper)
$groups = [];

foreach ($items as $item) {
    $key                     = $item->group ?? $item->lang;
    $groups[$key]['name']    = $item->groupName ?? $item->langName;
    $groups[$key]['lang']    = $item->lang;
    $groups[$key]['items'][] = $item;
}

// Headings: folder names always, language names only when several languages are shown
$folderMode   = FlipbookHelper::groupMode($params) === 'folders';
$collapse     = FlipbookHelper::collapseMode($params);
$showHeadings = $folderMode || count($groups) > 1;
$showSize     = (int) $params->get('show_size', 1) === 1;
$showDate     = (int) $params->get('show_date', 1) === 1;

// Dateigröße ohne Joomla-Helfer formatieren (z. B. „25,2 MB“)
$formatSize = static function (int $bytes, string $lang): string {
    $dec   = in_array($lang, ['en'], true) ? '.' : ',';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i     = 0;
    $value = (float) $bytes;

    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $i >= 2 ? 1 : 0, $dec, '') . ' ' . $units[$i];
};

// Datum als Text an Joomla übergeben (Joomla 6 erwartet einen String, keinen Zeitstempel)
$formatDate = static function (int $ts): string {
    try {
        return HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $ts), Text::_('DATE_FORMAT_LC4'));
    } catch (\Throwable $e) {
        return gmdate('d.m.Y', $ts);
    }
};

$pageLang = $items[0]->pageLang ?? '';
?>
<div class="mod-pdfflipbook mod-pdfflipbook--downloads<?php echo $suffix !== '' ? ' ' . $e($suffix) : ''; ?>"<?php echo $styleAttr; ?>>
    <?php if ($intro !== '') : ?>
        <div class="mod-pdfflipbook__intro"><?php echo $intro; ?></div>
    <?php endif; ?>

    <?php $groupIndex = 0; ?>
    <?php foreach ($groups as $group) : ?>
        <?php
        $hasHeading  = $showHeadings && $group['name'] !== '';
        $collapsible = $hasHeading && $collapse !== 'none';
        $open        = $collapse === 'first' && $groupIndex === 0;
        $groupIndex += $hasHeading ? 1 : 0;
        ?>
        <?php if ($collapsible) : ?>
        <details class="mod-pdfflipbook__group mod-pdfflipbook__group--collapsible" lang="<?php echo $e($group['lang']); ?>"<?php echo $open ? ' open' : ''; ?>>
            <summary class="mod-pdfflipbook__summary"><h3 class="mod-pdfflipbook__lang"><?php echo $e($group['name']); ?></h3></summary>
        <?php else : ?>
        <section class="mod-pdfflipbook__group" lang="<?php echo $e($group['lang']); ?>">
            <?php if ($hasHeading) : ?>
                <h3 class="mod-pdfflipbook__lang"><?php echo $e($group['name']); ?></h3>
            <?php endif; ?>
        <?php endif; ?>

            <table class="mod-pdfflipbook__table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo Text::_('MOD_PDFFLIPBOOK_COL_TITLE'); ?></th>
                        <?php if ($showSize) : ?>
                        <th scope="col" class="mod-pdfflipbook__col-size"><?php echo Text::_('MOD_PDFFLIPBOOK_COL_SIZE'); ?></th>
                        <?php endif; ?>
                        <?php if ($showDate) : ?>
                        <th scope="col" class="mod-pdfflipbook__col-date"><?php echo Text::_('MOD_PDFFLIPBOOK_COL_DATE'); ?></th>
                        <?php endif; ?>
                        <th scope="col" class="mod-pdfflipbook__col-actions"><span class="visually-hidden"><?php echo Text::_('MOD_PDFFLIPBOOK_COL_ACTIONS'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['items'] as $item) : ?>
                        <tr>
                            <td class="mod-pdfflipbook__col-title">
                                <a href="<?php echo $e($item->viewer); ?>" target="<?php echo $target; ?>"<?php echo $relAttr; ?>><?php echo $e($item->title); ?></a>
                                <?php if (!empty($item->description)) : ?>
                                    <div class="mod-pdfflipbook__desc"><?php echo nl2br($e($item->description)); ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($showSize) : ?>
                            <td class="mod-pdfflipbook__col-size"><?php echo $item->size ? $formatSize((int) $item->size, $pageLang) : ''; ?></td>
                            <?php endif; ?>
                            <?php if ($showDate) : ?>
                            <td class="mod-pdfflipbook__col-date"><?php echo $item->modified ? $e($formatDate((int) $item->modified)) : ''; ?></td>
                            <?php endif; ?>
                            <td class="mod-pdfflipbook__col-actions">
                                <a class="mod-pdfflipbook__btn mod-pdfflipbook__btn--open" href="<?php echo $e($item->viewer); ?>" target="<?php echo $target; ?>"<?php echo $relAttr; ?>><?php echo Text::_('MOD_PDFFLIPBOOK_OPEN'); ?></a>
                                <a class="mod-pdfflipbook__btn mod-pdfflipbook__btn--download" href="<?php echo $e($item->pdf); ?>" download><?php echo Text::_('MOD_PDFFLIPBOOK_DOWNLOAD_SHORT'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php echo $collapsible ? '</details>' : '</section>'; ?>
    <?php endforeach; ?>
</div>
