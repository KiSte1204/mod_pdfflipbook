<?php

/**
 * @package     PDF Flipbook Catalogs
 * @copyright   (C) Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Installer script: checks after installing or updating that the viewer files have arrived.
 * Some hosting virus scanners rename or remove JavaScript files (e.g. flipbook.js -> flipbook.j_).
 * Nothing is changed – the administrator only gets a message. Scanners sometimes act a little later,
 * therefore the module repeats this check on the frontend for administrators.
 */
class Mod_PdfflipbookInstallerScript
{
    private const FILES = ['index.html', 'flipbook.js', 'lang.js', 'flipbook.css', 'lib/pdfjs/pdf.min.js', 'lib/pdfjs/pdf.worker.min.js'];

    public function postflight(string $type, $parent): bool
    {
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        $dir     = JPATH_ROOT . '/media/mod_pdfflipbook/viewer';
        $missing = [];

        foreach (self::FILES as $file) {
            if (!is_file($dir . '/' . $file)) {
                $missing[] = 'media/mod_pdfflipbook/viewer/' . $file;
            }
        }

        if ($missing) {
            $list = implode('<br>', array_map(static fn ($f) => '<code>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</code>', $missing));
            Factory::getApplication()->enqueueMessage(Text::sprintf('MOD_PDFFLIPBOOK_INSTALL_FILES_MISSING', $list), 'warning');
        }

        return true;
    }
}
