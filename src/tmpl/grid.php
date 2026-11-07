<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Layout "Grid": catalogues side by side.
 *
 * @var \Joomla\Registry\Registry $params
 * @var object[]                  $items
 * @var string                    $notice
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;

if (empty($items)) {
    if (!empty($notice)) {
        echo '<div class="alert alert-warning mod-pdfflipbook__notice">' . $notice . '</div>';
    }

    return;
}

$variant = 'grid';
require ModuleHelper::getLayoutPath('mod_pdfflipbook', '_items');
