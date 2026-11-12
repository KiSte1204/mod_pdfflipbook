<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Form field "Order": lists all PDFs of the catalogue folder per language and lets the
 * administrator sort them by drag & drop (or with the arrow buttons).
 * The order is stored as JSON: {"de": ["10_a.pdf", ...], "en": [...], "": [...]}.
 */

namespace Webkiste\Module\PdfFlipbook\Site\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Webkiste\Module\PdfFlipbook\Site\Helper\FlipbookHelper;

\defined('_JEXEC') or die;

class FileorderField extends FormField
{
    protected $type = 'Fileorder';

    protected function getInput()
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $params    = new Registry($this->form ? $this->form->getValue('params') : []);
        $folder    = FlipbookHelper::folderSetting($params);
        $protected = FlipbookHelper::isProtected($params);
        $base      = FlipbookHelper::sourceFolder($folder, $protected);
        $newPos    = $params->get('new_files', 'top') === 'bottom' ? 'bottom' : 'top';
        $saved     = FlipbookHelper::parseOrdering($this->value);
        $overrides = FlipbookHelper::parseOverrides($params->get('overrides'));
        $thumbPx   = max(80, min(800, (int) $params->get('thumb_width', 160))) * 2;
        $root      = rtrim(Uri::root(true), '/');

        $hidden = '<input type="hidden" name="' . $e($this->name) . '" id="' . $e($this->id) . '" value="' . $e((string) $this->value) . '">';

        if ($base === null) {
            return $hidden . '<div class="alert alert-info">' . Text::sprintf('MOD_PDFFLIPBOOK_ORDER_NO_FOLDER', $e($folder)) . '</div>';
        }

        // Language folders (alphabetical by language name), or the main folder on single-language sites
        $groups = [];

        foreach (FlipbookHelper::languageFolders($base, '') as $lang) {
            $groups[$lang] = FlipbookHelper::listPdfs($base . '/' . $lang);
        }

        if (!$groups) {
            $groups[''] = FlipbookHelper::listPdfs($base);
        }

        $html  = '<div class="pfb-order" data-target="' . $e($this->id) . '">';
        $html .= $hidden;
        $html .= '<p class="pfb-order__help">' . Text::_('MOD_PDFFLIPBOOK_ORDER_HELP') . '</p>';

        $count = 0;

        foreach ($groups as $lang => $files) {
            [$ordered, $new] = FlipbookHelper::applyOrder($files, $saved[(string) $lang] ?? [], $newPos);
            $newKeys         = array_map(static fn ($f) => mb_strtolower(FlipbookHelper::nfc($f)), $new);
            $label           = $lang === ''
                ? Text::_('MOD_PDFFLIPBOOK_ORDER_MAIN_FOLDER')
                : (FlipbookHelper::LANGUAGE_NAMES[$lang] ?? strtoupper($lang)) . ' (' . $lang . ')';

            $html .= '<details class="pfb-order__group" open data-lang="' . $e($lang) . '">';
            $html .= '<summary><strong>' . $e($label) . '</strong> <span class="pfb-order__count">'
                . Text::plural('MOD_PDFFLIPBOOK_ORDER_N_FILES', \count($ordered)) . '</span></summary>';

            if (!$ordered) {
                $html .= '<p class="pfb-order__empty">' . Text::_('MOD_PDFFLIPBOOK_ORDER_EMPTY') . '</p></details>';
                continue;
            }

            $html .= '<div class="pfb-order__tools">'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-pfb-reset>'
                . Text::_('MOD_PDFFLIPBOOK_ORDER_RESET') . '</button></div>';
            $html .= '<ol class="pfb-order__list">';

            foreach ($ordered as $file) {
                $rel      = $lang === '' ? $file : $lang . '/' . $file;
                $override = FlipbookHelper::findOverride($overrides, $rel, $file);
                $title    = ($override && $override['title'] !== '')
                    ? $override['title']
                    : FlipbookHelper::buildTitle($file, $lang !== '' ? $lang : 'xx');
                $isNew    = \in_array(mb_strtolower(FlipbookHelper::nfc($file)), $newKeys, true);
                $isHidden = $override && !empty($override['hide']);

                // Existing thumbnail (only for public catalogues, nothing is generated here)
                $thumb = $protected
                    ? null
                    : FlipbookHelper::thumbnail($base . '/' . $rel, $rel, $thumbPx, false);

                $html .= '<li class="pfb-order__item" draggable="true" data-file="' . $e($file) . '" data-name="' . $e(mb_strtolower(FlipbookHelper::nfc($file))) . '">';
                $html .= '<span class="pfb-order__handle" aria-hidden="true">⋮⋮</span>';
                $html .= $thumb
                    ? '<img class="pfb-order__thumb" src="' . $e($root . '/' . FlipbookHelper::THUMB_DIR . '/' . $thumb) . '" alt="" loading="lazy">'
                    : '<span class="pfb-order__thumb pfb-order__thumb--none" aria-hidden="true">PDF</span>';
                $html .= '<span class="pfb-order__text"><span class="pfb-order__title">' . $e($title) . '</span>'
                    . '<code class="pfb-order__file">' . $e($file) . '</code></span>';

                if ($isNew) {
                    $html .= '<span class="badge bg-success">' . Text::_('MOD_PDFFLIPBOOK_ORDER_NEW') . '</span>';
                }

                if ($isHidden) {
                    $html .= '<span class="badge bg-secondary">' . Text::_('MOD_PDFFLIPBOOK_ORDER_HIDDEN') . '</span>';
                }

                $html .= '<span class="pfb-order__move">'
                    . '<button type="button" class="btn btn-sm btn-light" data-pfb-up aria-label="' . $e(Text::sprintf('MOD_PDFFLIPBOOK_ORDER_UP', $title)) . '" title="' . $e(Text::_('MOD_PDFFLIPBOOK_ORDER_UP_SHORT')) . '">▲</button>'
                    . '<button type="button" class="btn btn-sm btn-light" data-pfb-down aria-label="' . $e(Text::sprintf('MOD_PDFFLIPBOOK_ORDER_DOWN', $title)) . '" title="' . $e(Text::_('MOD_PDFFLIPBOOK_ORDER_DOWN_SHORT')) . '">▼</button>'
                    . '</span>';
                $html .= '</li>';
                $count++;
            }

            $html .= '</ol></details>';
        }

        if ($count === 0) {
            $html .= '<div class="alert alert-info">' . Text::sprintf('MOD_PDFFLIPBOOK_ORDER_NO_FILES', $e($folder)) . '</div>';
        }

        $html .= '</div>';

        $this->addAssets();

        return $html;
    }

    private function addAssets(): void
    {
        static $done = false;

        if ($done) {
            return;
        }

        $done = true;
        $wa   = Factory::getApplication()->getDocument()->getWebAssetManager();

        $wa->addInlineStyle(self::css());
        $wa->addInlineScript(self::js(), [], ['type' => 'module']);
    }

    public static function css(): string
    {
        return <<<'CSS'
.pfb-order__help { margin: 0 0 .75rem; }
.pfb-order__group { margin: 0 0 1rem; border: 1px solid var(--template-bg-dark-7, #dfe3e7); border-radius: .35rem; padding: .5rem .75rem; }
.pfb-order__group > summary { cursor: pointer; padding: .25rem 0; }
.pfb-order__count { opacity: .7; font-size: .9em; margin-left: .35rem; }
.pfb-order__tools { display: flex; justify-content: flex-end; margin: .25rem 0 .5rem; }
.pfb-order__list { list-style: none; margin: 0; padding: 0; }
.pfb-order__item { display: flex; align-items: center; gap: .6rem; padding: .4rem .5rem; margin: 0 0 .35rem; background: var(--body-bg, #fff); border: 1px solid var(--template-bg-dark-7, #dfe3e7); border-radius: .3rem; cursor: grab; }
.pfb-order__item.is-dragging { opacity: .45; }
.pfb-order__item.is-over { box-shadow: 0 -3px 0 0 var(--template-link-color, #2a69b8); }
.pfb-order__item.is-over-after { box-shadow: 0 3px 0 0 var(--template-link-color, #2a69b8); }
.pfb-order__handle { opacity: .45; letter-spacing: -2px; user-select: none; }
.pfb-order__thumb { width: 32px; height: 45px; object-fit: cover; border: 1px solid #ddd; background: #f4f4f4; flex: 0 0 auto; }
.pfb-order__thumb--none { display: inline-flex; align-items: center; justify-content: center; font-size: .6rem; color: #888; }
.pfb-order__text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
.pfb-order__title { font-weight: 600; }
.pfb-order__file { font-size: .8em; line-height: 1.5; padding-bottom: 1px; opacity: .75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pfb-order__move { display: inline-flex; gap: .25rem; flex: 0 0 auto; }
.pfb-order__move .btn { padding: .1rem .45rem; line-height: 1.2; }
.pfb-order__empty { opacity: .7; margin: .25rem 0; }
CSS;
    }

    public static function js(): string
    {
        return <<<'JS'
const collect = (root) => {
  const input = document.getElementById(root.dataset.target);
  if (!input) return;
  const data = {};
  root.querySelectorAll('.pfb-order__group').forEach((g) => {
    data[g.dataset.lang] = [...g.querySelectorAll('.pfb-order__item')].map((li) => li.dataset.file);
  });
  input.value = JSON.stringify(data);
};

document.querySelectorAll('.pfb-order').forEach((root) => {
  let dragged = null;

  root.querySelectorAll('.pfb-order__list').forEach((list) => {
    list.addEventListener('dragstart', (e) => {
      const li = e.target.closest('.pfb-order__item');
      if (!li) return;
      dragged = li;
      li.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', li.dataset.file);
    });

    list.addEventListener('dragover', (e) => {
      const li = e.target.closest('.pfb-order__item');
      // Only within the same language list
      if (!dragged || !li || li === dragged || li.parentNode !== dragged.parentNode) return;
      e.preventDefault();
      const r = li.getBoundingClientRect();
      const after = e.clientY > r.top + r.height / 2;
      list.querySelectorAll('.is-over, .is-over-after').forEach((x) => x.classList.remove('is-over', 'is-over-after'));
      li.classList.add(after ? 'is-over-after' : 'is-over');
    });

    list.addEventListener('drop', (e) => {
      const li = e.target.closest('.pfb-order__item');
      if (!dragged || !li || li === dragged || li.parentNode !== dragged.parentNode) return;
      e.preventDefault();
      const r = li.getBoundingClientRect();
      li.parentNode.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? li.nextSibling : li);
      collect(root);
    });

    list.addEventListener('dragend', () => {
      if (dragged) dragged.classList.remove('is-dragging');
      dragged = null;
      list.querySelectorAll('.is-over, .is-over-after').forEach((x) => x.classList.remove('is-over', 'is-over-after'));
    });
  });

  root.addEventListener('click', (e) => {
    const up = e.target.closest('[data-pfb-up]');
    const down = e.target.closest('[data-pfb-down]');
    const reset = e.target.closest('[data-pfb-reset]');

    if (up || down) {
      const li = (up || down).closest('.pfb-order__item');
      if (up && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
      if (down && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
      (up || down).focus();
      collect(root);
    }

    if (reset) {
      const group = reset.closest('.pfb-order__group');
      const list = group.querySelector('.pfb-order__list');
      const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
      [...list.children]
        .sort((a, b) => collator.compare(a.dataset.name, b.dataset.name))
        .forEach((li) => list.appendChild(li));
      collect(root);
    }
  });

  // Store the displayed order right away, so new files keep their position after saving
  collect(root);
});
JS;
    }
}
