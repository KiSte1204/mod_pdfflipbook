<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Delivers the files of protected catalogues (option "Protect files") after checking
 * the access level of the module:
 *   index.php?option=com_ajax&module=pdfflipbook&method=file&format=raw&id=<module>&f=<de/file.pdf>
 *   index.php?option=com_ajax&module=pdfflipbook&method=file&format=raw&id=<module>&t=thumb&f=<thumbnail>
 */

namespace Webkiste\Module\PdfFlipbook\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

class PdfflipbookHelper
{
    /**
     * Called by com_ajax (method=file).
     */
    public function fileAjax(): string
    {
        $app   = Factory::getApplication();
        $input = $app->getInput();
        $id    = $input->getInt('id');
        $type  = $input->getCmd('t', 'pdf') === 'thumb' ? 'thumb' : 'pdf';
        $file  = (string) $input->get('f', '', 'string');

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'access', 'params', 'published']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_pdfflipbook'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->bind(':id', $id, ParameterType::INTEGER);

        $module = $db->setQuery($query)->loadObject();

        if (!$module || (int) $module->published !== 1) {
            return $this->deny(404);
        }

        $params = new Registry($module->params);

        // Only protected catalogues are delivered this way
        if (!FlipbookHelper::isProtected($params)) {
            return $this->deny(404);
        }

        $user = $app->getIdentity();

        if (!$user || !\in_array((int) $module->access, array_map('intval', $user->getAuthorisedViewLevels()), true)) {
            return $this->deny(403);
        }

        $base = FlipbookHelper::sourceFolder(FlipbookHelper::folderSetting($params), true);
        $path = $base !== null
            ? FlipbookHelper::protectedFile($base, $file, $type, FlipbookHelper::parseOverrides($params->get('overrides')))
            : null;

        if ($path === null) {
            return $this->deny(404);
        }

        $this->send($path, $type);
        $app->close();

        return '';
    }

    private function deny(int $status): string
    {
        $app = Factory::getApplication();
        $app->setHeader('status', $status, true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        return $status === 403 ? 'Access denied' : 'Not found';
    }

    private function send(string $path, string $type): void
    {
        // Discard anything that was buffered so far (the file is sent unchanged)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $name = basename($path);
        $size = (int) filesize($path);

        header('Content-Type: ' . ($type === 'thumb' ? 'image/jpeg' : 'application/pdf'));
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . addcslashes($this->asciiName($name), '"\\')
            . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT');
        // Never stored by shared caches or proxies – only the browser of the logged-in user
        header('Cache-Control: private, max-age=' . ($type === 'thumb' ? 3600 : 0) . ', must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
    }

    private function asciiName(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        return ($ascii !== false && $ascii !== '') ? preg_replace('/[^\x20-\x7e]/', '_', $ascii) : 'catalogue.pdf';
    }
}
