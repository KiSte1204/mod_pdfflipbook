<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Webkiste\Module\PdfFlipbook\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;
use Joomla\CMS\Language\Text;
use Webkiste\Module\PdfFlipbook\Site\Helper\FlipbookHelper;

\defined('_JEXEC') or die;

class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        $helper        = $this->getHelperFactory()->getHelper('FlipbookHelper');
        $data['items'] = $helper->getItems($data['params'], $this->getApplication(), (int) $this->module->id);

        // Setup hints – only for logged-in users who may manage modules
        // (Joomla does not cache module output for logged-in users, so visitors never see it)
        $data['notice'] = '';
        $user           = $this->getApplication()->getIdentity();
        $isManager      = $user && !$user->guest
            && ($user->authorise('core.admin') || $user->authorise('core.manage', 'com_modules'));

        if ($isManager) {
            $hints = [];

            if (!$data['items'] && $helper->notice) {
                [$key, $folder] = $helper->notice;
                $hints[]        = Text::sprintf($key, htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'));
            }

            if ($helper->warning) {
                [$key, $folder] = $helper->warning;
                $hints[]        = Text::sprintf($key, htmlspecialchars($folder, ENT_QUOTES, 'UTF-8'));
            }

            // Protected files with access level "Public" are delivered to everybody
            if (FlipbookHelper::isProtected($data['params']) && (int) $this->module->access === 1) {
                $hints[] = Text::_('MOD_PDFFLIPBOOK_WARNING_PUBLIC');
            }

            $data['notice'] = implode('<br>', $hints);
        }

        // Accent colour for the module output (thumbnail border, buttons) as CSS custom properties
        $data['styleAttr'] = '';

        if ((int) $data['params']->get('color_module', 1) === 1) {
            $accent            = FlipbookHelper::colors($data['params'])['accent'];
            $data['styleAttr'] = ' style="--pfb-accent:' . $accent . ';--pfb-accent-dark:' . FlipbookHelper::darken($accent) . '"';
        }

        $this->getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseStyle('mod_pdfflipbook', 'mod_pdfflipbook/flipbook.css');

        return $data;
    }
}
