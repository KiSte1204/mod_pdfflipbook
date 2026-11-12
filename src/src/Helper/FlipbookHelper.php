<?php

/**
 * @package     Webkiste.PdfFlipbook
 * @subpackage  mod_pdfflipbook
 *
 * @copyright   (C) 2026 Stefan Kirchhoff, webkiste.net
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Reads the PDFs from <folder>/<language>/ (e.g. images/flipbooks/en/),
 * builds the titles from the file names, creates cover thumbnails (Imagick)
 * and builds the links into the flipbook viewer.
 *
 * File name:  10_Main_Catalogue_2026-2027_EN.pdf
 *   10_      sort order (not displayed)
 *   _EN      language code of the folder (not displayed)
 *   _        becomes a space
 */

namespace Webkiste\Module\PdfFlipbook\Site\Helper;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

class FlipbookHelper
{
    /**
     * Why nothing was found (shown to administrators only), null if PDFs were found.
     *
     * @var  array{0: string, 1: string}|null  [language key, folder]
     */
    public ?array $notice = null;

    /**
     * Problem with the access protection (shown to administrators only), null if everything is fine.
     *
     * @var  array{0: string, 1: string}|null  [language key, folder]
     */
    public ?array $warning = null;

    /** Thumbnail cache (relative to the Joomla root) */
    public const THUMB_DIR = 'media/mod_pdfflipbook/thumbs';

    /** Thumbnails of protected catalogues are kept in this subfolder of the catalogue folder */
    public const PROTECTED_THUMB_DIR = '.thumbs';

    /** Marker in the .htaccess written by this module */
    public const HTACCESS_MARKER = '# mod_pdfflipbook: protected catalogue folder';

    /** Built-in viewer (relative to the Joomla root) */
    public const VIEWER_PATH = 'media/mod_pdfflipbook/viewer/index.html';

    /** Default colours (also used by the viewer) */
    public const DEFAULT_COLORS = [
        'accent' => '#2a6fdb',
        'bar'    => '#1f2328',
        'barfg'  => '#f2f2f2',
        'stage'  => '#e9ecef',
    ];

    /** Erhöhen, wenn sich die Bilderzeugung ändert – dann werden alle Vorschaubilder neu erstellt */
    public const THUMB_VERSION = 2;

    /** Sprachnamen in der jeweiligen Sprache (für die Download-Übersicht) */
    public const LANGUAGE_NAMES = [
        'de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano',
        'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português', 'cs' => 'Čeština', 'tr' => 'Türkçe',
        'da' => 'Dansk', 'sv' => 'Svenska', 'fi' => 'Suomi', 'no' => 'Norsk', 'hu' => 'Magyar', 'ru' => 'Русский',
    ];

    /**
     * Kataloge für die aktuelle Seitensprache.
     *
     * @return  object[]
     */
    public function getItems(Registry $params, $app, int $moduleId = 0): array
    {
        $pageLang  = strtolower(substr((string) $app->getLanguage()->getTag(), 0, 2));
        $fallback  = strtolower(trim((string) $params->get('fallback_lang', 'en'))) ?: 'en';
        $folder    = self::folderSetting($params);
        $protected = $moduleId > 0 && self::isProtected($params);

        $base          = self::sourceFolder($folder, $protected);
        $this->notice  = null;
        $this->warning = null;

        if ($base === null) {
            Log::add('Flipbook folder not found: ' . $folder, Log::WARNING, 'mod_pdfflipbook');
            $this->notice = ['MOD_PDFFLIPBOOK_NOTICE_NO_FOLDER', $folder];

            return [];
        }

        // Welche Sprachordner? Standard: aktuelle Sprache (mit Ersatzsprache), oder alle Sprachen
        $groups = [];

        if ($params->get('scope', 'current') === 'all') {
            foreach (self::languageFolders($base, $pageLang) as $lang) {
                [, $files] = self::findFiles($base, $lang, $lang);

                if ($files) {
                    $groups[$lang] = $files;
                }
            }
        } else {
            [$usedLang, $files] = self::findFiles($base, $pageLang, $fallback);

            if ($files) {
                $groups[$usedLang] = $files;
            }
        }

        // Single-language site: no language folders at all -> PDFs directly in the main folder
        if (!$groups && !self::languageFolders($base, $pageLang)) {
            $files = self::listPdfs($base);

            if ($files) {
                $groups[''] = $files;
            }
        }

        if (!$groups) {
            $this->notice = ['MOD_PDFFLIPBOOK_NOTICE_NO_FILES', $folder];

            return [];
        }

        // Protected: block direct access to the folder (files are delivered through Joomla)
        if ($protected) {
            $problem = self::ensureProtection($base);

            if ($problem !== null) {
                $this->warning = [$problem, $folder];
            }
        }

        // Sort order: "name" (number prefix / file name, default), "name_desc", "manual", "newest" or "title"
        $sort = self::sortMode($params);

        if ($sort === 'manual') {
            $orders = self::parseOrdering($params->get('ordering', ''));
            $newPos = $params->get('new_files', 'top') === 'bottom' ? 'bottom' : 'top';

            foreach ($groups as $key => $files) {
                $groups[$key] = self::applyOrder($files, $orders[(string) $key] ?? [], $newPos)[0];
            }
        }

        $overrides  = self::parseOverrides($params->get('overrides'));
        $root       = rtrim(Uri::root(true), '/');
        $viewerUrl  = self::viewerUrl((string) $params->get('viewer_url', ''), $root);
        $style      = self::viewerStyle($params);
        $thumbWidth = max(80, min(800, (int) $params->get('thumb_width', 160)));
        $folderUrl  = $root . '/' . implode('/', array_map('rawurlencode', explode('/', $folder)));

        // Im Download-Layout werden keine neuen Vorschaubilder erzeugt (vorhandene werden genutzt)
        $makeThumbs = !str_ends_with((string) $params->get('layout', ''), 'downloads');

        $items = [];

        foreach ($groups as $usedLang => $files) {
            $groupItems = [];

            // '' = PDFs directly in the main folder (site language is used for the title)
            $inRoot   = $usedLang === '';
            $usedLang = $inRoot ? $pageLang : $usedLang;

            foreach ($files as $file) {
                $rel      = $inRoot ? $file : $usedLang . '/' . $file;
                $override = self::findOverride($overrides, $rel, $file);

                if ($override && !empty($override['hide'])) {
                    continue;
                }

                $title   = ($override && $override['title'] !== '') ? $override['title'] : self::buildTitle($file, $usedLang);
                $path    = $base . '/' . $rel;
                $pdfUrl  = $protected
                    ? self::protectedUrl($root, $moduleId, $rel)
                    : $folderUrl . '/' . ($inRoot ? '' : rawurlencode($usedLang) . '/') . rawurlencode($file);

                $item = (object) [
                    'title'       => $title,
                    'description' => $override ? $override['description'] : '',
                    'file'        => $file,
                    'lang'        => $usedLang,
                    'langName'    => self::LANGUAGE_NAMES[$usedLang] ?? strtoupper($usedLang),
                    'isFallback'  => $usedLang !== $pageLang,
                    'pageLang'    => $pageLang,
                    'pdf'         => $pdfUrl,
                    'size'        => (int) @filesize($path),
                    'modified'    => (int) @filemtime($path),
                    'viewer'      => self::viewerLink($viewerUrl, $pdfUrl, $pageLang, $title, null, $style),
                    'thumb'       => null,
                    'thumbWidth'  => $thumbWidth,
                    'thumbHeight' => null,
                ];

                // Vorschaubild in doppelter Auflösung (scharf auf Retina-Displays)
                $thumbDir = $protected ? $base . '/' . self::PROTECTED_THUMB_DIR : JPATH_ROOT . '/' . self::THUMB_DIR;
                $thumb    = self::thumbnail($path, $rel, $thumbWidth * 2, $makeThumbs, $thumbDir);

                if ($thumb !== null) {
                    $item->thumb = $protected
                        ? self::protectedUrl($root, $moduleId, $thumb, 'thumb')
                        : $root . '/' . self::THUMB_DIR . '/' . $thumb;
                    $size        = @getimagesize($thumbDir . '/' . $thumb);

                    if ($size && $size[0] > 0) {
                        $item->thumbHeight = (int) round($thumbWidth * $size[1] / $size[0]);
                    }

                    // Blätterkatalog zeigt das Vorschaubild sofort als Titelseite, während das PDF lädt
                    $item->viewer = self::viewerLink($viewerUrl, $pdfUrl, $pageLang, $title, $item->thumb, $style);
                }

                $groupItems[] = $item;
            }

            foreach (self::sortItems($groupItems, $sort) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // Reine Hilfsfunktionen (ohne Joomla-Abhängigkeiten, einzeln testbar)
    // ------------------------------------------------------------------

    /**
     * Sort mode from the module settings.
     */
    public static function sortMode(Registry $params): string
    {
        $sort = (string) $params->get('sort', 'name');

        return \in_array($sort, ['name', 'name_desc', 'manual', 'newest', 'title'], true) ? $sort : 'name';
    }

    /**
     * Saved manual order: {"de": ["10_a.pdf", "b.pdf"], "en": [...], "": [...]}
     * ("" = PDFs directly in the main folder).
     *
     * @return  array<string, string[]>
     */
    public static function parseOrdering($value): array
    {
        if (\is_string($value)) {
            $value = trim($value) === '' ? [] : json_decode($value, true);
        } elseif (\is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (!\is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $lang => $files) {
            $lang = (string) $lang;

            if (($lang !== '' && !preg_match('/^[a-z]{2,3}$/', $lang)) || !\is_array($files)) {
                continue;
            }

            $list = [];

            foreach ($files as $file) {
                if (\is_string($file) && $file !== '' && !str_contains($file, '/') && !str_contains($file, '\\')) {
                    $list[] = $file;
                }
            }

            $result[$lang] = $list;
        }

        return $result;
    }

    /**
     * Puts the files into the saved order. Files that are not in the saved order yet
     * ("new" files) are placed at the top or bottom in file name order;
     * files that no longer exist are ignored.
     *
     * @param   string[]  $files   Existing files (already in file name order)
     * @param   string[]  $saved   Saved order
     * @param   string    $newPos  "top" or "bottom"
     *
     * @return  array{0: string[], 1: string[]}  [ordered files, new files]
     */
    public static function applyOrder(array $files, array $saved, string $newPos = 'top'): array
    {
        $byKey = [];

        foreach ($files as $file) {
            $byKey[mb_strtolower(self::nfc($file))] = $file;
        }

        $known = [];

        foreach ($saved as $file) {
            $key = mb_strtolower(self::nfc((string) $file));

            if (isset($byKey[$key])) {
                $known[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }

        $new = array_values($byKey);

        return [$newPos === 'bottom' ? array_merge($known, $new) : array_merge($new, $known), $new];
    }

    /**
     * Sorts the items of one language group ("name" and "manual" keep the given order).
     *
     * @param   object[]  $items
     *
     * @return  object[]
     */
    public static function sortItems(array $items, string $sort): array
    {
        if ($sort === 'name_desc') {
            usort($items, static fn ($a, $b) => strnatcasecmp(self::nfc($b->file), self::nfc($a->file)));
        } elseif ($sort === 'newest') {
            usort($items, static fn ($a, $b) => ($b->modified <=> $a->modified)
                ?: strnatcasecmp(self::nfc($a->file), self::nfc($b->file)));
        } elseif ($sort === 'title') {
            usort($items, static fn ($a, $b) => strnatcasecmp(self::nfc($a->title), self::nfc($b->title))
                ?: strnatcasecmp(self::nfc($a->file), self::nfc($b->file)));
        }

        return $items;
    }

    public static function folderSetting(Registry $params): string
    {
        $folder = trim(str_replace('\\', '/', (string) $params->get('folder', 'images/flipbooks')));

        // Absolute paths keep their leading slash (only allowed for protected catalogues)
        return self::isAbsolute($folder) ? rtrim($folder, '/') : trim($folder, '/');
    }

    public static function isProtected(Registry $params): bool
    {
        return (int) $params->get('protect', 0) === 1;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    /**
     * Catalogue folder: inside the Joomla root, or – for protected catalogues only –
     * an absolute path anywhere on the server (e.g. outside the web root).
     */
    public static function sourceFolder(string $folder, bool $protected): ?string
    {
        if ($protected && self::isAbsolute($folder)) {
            $real = realpath($folder);

            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        // "/images/flipbooks" is read relative to the Joomla root as before
        return self::resolveFolder(JPATH_ROOT, ltrim($folder, '/'));
    }

    /**
     * Address under which Joomla delivers a protected file after checking the access level.
     */
    public static function protectedUrl(string $root, int $moduleId, string $file, string $type = 'pdf'): string
    {
        $args = ['option' => 'com_ajax', 'module' => 'pdfflipbook', 'method' => 'file', 'format' => 'raw', 'id' => $moduleId];

        if ($type === 'thumb') {
            $args['t'] = 'thumb';
        }

        $args['f'] = $file;

        return $root . '/index.php?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Blocks direct web access to a protected folder inside the web root.
     *
     * @return  string|null  Language key of a problem, null if the folder is protected
     */
    public static function ensureProtection(string $base, ?string $webRoot = null, ?string $server = null): ?string
    {
        $webRoot = realpath($webRoot ?? JPATH_ROOT) ?: '';
        $server  = strtolower($server ?? (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        // Outside the web root nobody can reach the files directly
        if ($webRoot === '' || !str_starts_with($base . '/', rtrim($webRoot, '/') . '/')) {
            return null;
        }

        $file = $base . '/.htaccess';
        $rule = self::HTACCESS_MARKER . "\n"
            . "# Files are only delivered through Joomla after checking the access level.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

        $current = is_file($file) ? (string) @file_get_contents($file) : '';

        if (!str_contains($current, self::HTACCESS_MARKER)) {
            if ($current !== '' || @file_put_contents($file, $rule) === false) {
                // Existing foreign .htaccess is left alone; the administrator has to check it
                return $current !== '' ? 'MOD_PDFFLIPBOOK_WARNING_HTACCESS_FOREIGN' : 'MOD_PDFFLIPBOOK_WARNING_HTACCESS_WRITE';
            }
        }

        if (!is_file($base . '/index.html')) {
            @file_put_contents($base . '/index.html', '<!DOCTYPE html><title></title>');
        }

        // nginx and some other servers ignore .htaccess
        if ($server !== '' && !str_contains($server, 'apache') && !str_contains($server, 'litespeed')) {
            return 'MOD_PDFFLIPBOOK_WARNING_SERVER';
        }

        return null;
    }

    /**
     * Validates a file requested through the protected delivery.
     *
     * @return  string|null  Absolute path, null if the file is not allowed
     */
    public static function protectedFile(string $base, string $file, string $type, array $overrides = []): ?string
    {
        $file = str_replace('\\', '/', $file);

        if ($file === '' || str_contains($file, "\0") || str_starts_with($file, '/')) {
            return null;
        }

        if ($type === 'thumb') {
            if (!preg_match('/^[0-9a-f]{32}-\d+-\d+-v\d+\.jpg$/', $file)) {
                return null;
            }

            $path = $base . '/' . self::PROTECTED_THUMB_DIR . '/' . $file;

            return is_file($path) ? $path : null;
        }

        $parts = explode('/', $file);

        // Only "<file>.pdf" or "<language>/<file>.pdf", no hidden files or folders
        if (\count($parts) > 2 || (\count($parts) === 2 && !preg_match('/^[a-z]{2,3}$/', $parts[0]))) {
            return null;
        }

        $name = end($parts);

        if ($name === '' || $name[0] === '.' || !preg_match('/\.pdf$/i', $name)) {
            return null;
        }

        $real = realpath($base . '/' . $file);

        if ($real === false || !is_file($real) || !str_starts_with($real, rtrim($base, '/') . '/')) {
            return null;
        }

        $override = self::findOverride($overrides, $file, $name);

        if ($override && !empty($override['hide'])) {
            return null;
        }

        return $real;
    }

    /**
     * Ordner sicher auflösen: muss existieren und innerhalb des Joomla-Verzeichnisses liegen.
     */
    public static function resolveFolder(string $root, string $folder): ?string
    {
        $rootReal = realpath($root);
        $real     = realpath($root . '/' . $folder);

        if ($rootReal === false || $real === false || !is_dir($real)) {
            return null;
        }

        if (strpos($real . '/', rtrim($rootReal, '/') . '/') !== 0) {
            return null;
        }

        return $real;
    }

    /**
     * Alle Sprachordner, aktuelle Sprache zuerst, dann alphabetisch nach Sprachname.
     */
    public static function languageFolders(string $base, string $pageLang): array
    {
        $langs = [];

        foreach (scandir($base) ?: [] as $entry) {
            if (preg_match('/^[a-z]{2,3}$/', $entry) && is_dir($base . '/' . $entry)) {
                $langs[] = $entry;
            }
        }

        usort($langs, static function ($a, $b) use ($pageLang) {
            if ($a === $pageLang || $b === $pageLang) {
                return $a === $pageLang ? -1 : 1;
            }

            return strcmp(self::LANGUAGE_NAMES[$a] ?? $a, self::LANGUAGE_NAMES[$b] ?? $b);
        });

        return $langs;
    }

    /**
     * PDFs der Seitensprache, sonst der Ersatzsprache.
     *
     * @return  array  [verwendete Sprache, Dateinamen sortiert]
     */
    /**
     * PDF files of a folder, naturally sorted (2_ before 10_, case-insensitive).
     *
     * @return  string[]
     */
    public static function listPdfs(string $dir): array
    {
        $files = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry[0] === '.' || !preg_match('/\.pdf$/i', $entry) || !is_file($dir . '/' . $entry)) {
                continue;
            }

            $files[] = $entry;
        }

        usort($files, static fn ($a, $b) => strnatcasecmp(self::nfc($a), self::nfc($b)));

        return $files;
    }

    public static function findFiles(string $base, string $pageLang, string $fallback): array
    {
        foreach (array_unique([$pageLang, $fallback]) as $lang) {
            if (!preg_match('/^[a-z]{2,3}$/', $lang)) {
                continue;
            }

            $dir = $base . '/' . $lang;

            if (!is_dir($dir)) {
                continue;
            }

            $files = self::listPdfs($dir);

            if ($files) {
                return [$lang, $files];
            }
        }

        return [$pageLang, []];
    }

    /**
     * Angezeigter Titel aus dem Dateinamen.
     *   10_Main_Catalogue_2026-2027_EN.pdf  ->  Main Catalogue 2026-2027
     */
    public static function buildTitle(string $file, string $lang): string
    {
        $name = self::nfc(preg_replace('/\.pdf$/i', '', $file));

        // Reihenfolge-Nummer am Anfang
        $name = preg_replace('/^\d+[\s_.\-]+/u', '', $name);

        // Sprachkürzel am Ende oder Anfang (beliebige Schreibweise) …
        $code = preg_quote($lang, '/');
        $name = preg_replace('/[\s_\-]+' . $code . '$/iu', '', $name);
        $name = preg_replace('/^' . $code . '[\s_\-]+/iu', '', $name);

        // … oder in der Mitte, dann nur in Großbuchstaben (damit Wörter wie „es“ erhalten bleiben)
        $name = preg_replace('/(?<=[\s_\-])' . strtoupper($code) . '(?=[\s_\-])/u', '', $name);

        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = preg_replace('/\s*-\s*-\s*/u', ' - ', $name);

        return trim($name, " -\t");
    }

    /**
     * Einstellungen „Titel / Ausblenden“ aus dem Modul.
     */
    public static function parseOverrides($value): array
    {
        $result = [];

        foreach ((array) $value as $row) {
            $row  = (array) $row;
            $file = trim(str_replace('\\', '/', (string) ($row['file'] ?? '')), '/ ');

            if ($file === '') {
                continue;
            }

            $result[mb_strtolower(self::nfc($file))] = [
                'title'       => trim((string) ($row['title'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'hide'        => (int) ($row['hide'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Passende Einstellung zu einer Datei: erst „de/datei.pdf“, dann nur „datei.pdf“.
     */
    public static function findOverride(array $overrides, string $rel, string $file): ?array
    {
        foreach ([$rel, $file] as $key) {
            $key = mb_strtolower(self::nfc($key));

            if (isset($overrides[$key])) {
                return $overrides[$key];
            }
        }

        return null;
    }

    /**
     * Viewer address: empty = built-in viewer, otherwise relative to the Joomla root or a full URL.
     */
    public static function viewerUrl(string $setting, string $root): string
    {
        $setting = trim($setting);

        if ($setting === '') {
            return $root . '/' . self::VIEWER_PATH;
        }

        if (preg_match('#^(https?:)?//#i', $setting) || str_starts_with($setting, '/')) {
            return $setting;
        }

        return $root . '/' . ltrim($setting, '/');
    }

    /**
     * Colours and flip duration for the viewer (only values that differ from the defaults).
     *
     * @return  array<string, string|int>
     */
    public static function viewerStyle(Registry $params): array
    {
        $style = [];

        foreach (self::colors($params) as $key => $hex) {
            if ($hex !== self::DEFAULT_COLORS[$key]) {
                $style[$key] = ltrim($hex, '#');
            }
        }

        $flip = (int) $params->get('flip_ms', 800);

        if ($flip >= 100 && $flip <= 3000 && $flip !== 800) {
            $style['flip'] = $flip;
        }

        return $style;
    }

    /**
     * Validated colours from the module settings (#rrggbb, lower case).
     *
     * @return  array<string, string>
     */
    public static function colors(Registry $params): array
    {
        $map = ['accent' => 'accent_color', 'bar' => 'bar_bg', 'barfg' => 'bar_fg', 'stage' => 'stage_bg'];
        $out = [];

        foreach ($map as $key => $param) {
            $out[$key] = self::normalizeHex((string) $params->get($param, '')) ?? self::DEFAULT_COLORS[$key];
        }

        return $out;
    }

    /**
     * "#abc" / "aabbcc" / "#AABBCC" -> "#aabbcc", anything else -> null.
     */
    public static function normalizeHex(string $value): ?string
    {
        $value = strtolower(ltrim(trim($value), '#'));

        if (preg_match('/^[0-9a-f]{3}$/', $value)) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $value) ? '#' . $value : null;
    }

    /**
     * Darker shade of a colour (for hover states), $factor 0..1.
     */
    public static function darken(string $hex, float $factor = 0.18): string
    {
        $hex = self::normalizeHex($hex) ?? self::DEFAULT_COLORS['accent'];
        $rgb = array_map('hexdec', str_split(substr($hex, 1), 2));

        return '#' . implode('', array_map(
            static fn ($c) => str_pad(dechex((int) round($c * (1 - $factor))), 2, '0', STR_PAD_LEFT),
            $rgb
        ));
    }

    public static function viewerLink(string $viewerUrl, string $pdfUrl, string $lang, string $title, ?string $cover = null, array $style = []): string
    {
        $args = ['pdf' => $pdfUrl, 'lang' => $lang, 'langswitch' => 0, 'title' => $title];

        if ($cover !== null && $cover !== '') {
            $args['cover'] = $cover;
        }

        $args += $style;

        $query = http_build_query(
            $args,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $viewerUrl . (str_contains($viewerUrl, '?') ? '&' : '?') . $query;
    }

    /**
     * Vorschaubild der ersten PDF-Seite. Wird einmalig erzeugt und erneuert,
     * sobald das PDF ersetzt wird (geändertes Dateidatum).
     *
     * @return  string|null  Dateiname im Vorschau-Ordner
     */
    public static function thumbnail(string $pdfPath, string $rel, int $px, bool $generate = true, ?string $dir = null): ?string
    {
        $dir   = $dir ?? JPATH_ROOT . '/' . self::THUMB_DIR;
        $key   = md5(mb_strtolower(self::nfc($rel)));
        $mtime = (int) @filemtime($pdfPath);
        $name  = $key . '-' . $mtime . '-' . $px . '-v' . self::THUMB_VERSION . '.jpg';

        if (is_file($dir . '/' . $name)) {
            return $name;
        }

        // Fehlgeschlagene Versuche merken, damit nicht jeder Seitenaufruf erneut rechnet
        if (!$generate || is_file($dir . '/' . $name . '.error')) {
            return null;
        }

        if (!class_exists('Imagick')) {
            return null;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            Log::add('Cannot create thumbnail folder: ' . $dir, Log::WARNING, 'mod_pdfflipbook');

            return null;
        }

        try {
            @set_time_limit(60);

            $im = new \Imagick();
            // Nur den sichtbaren Seitenbereich (CropBox) rendern – sonst erscheinen
            // Beschnittzugabe bzw. weißer Rand von Druck-PDFs im Vorschaubild
            $im->setOption('pdf:use-cropbox', 'true');
            $im->setResolution(110, 110);
            $im->readImage($pdfPath . '[0]');

            if ($im->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                // Druck-PDFs sind oft CMYK – für den Browser nach RGB umwandeln
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            $im->setImageBackgroundColor('white');
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->thumbnailImage($px, 0);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);
            $im->stripImage();

            $tmp = $dir . '/' . $name . '.tmp';
            $im->writeImage($tmp);
            $im->clear();
            rename($tmp, $dir . '/' . $name);

            // Ältere Vorschaubilder derselben Datei entfernen
            foreach (glob($dir . '/' . $key . '-*') ?: [] as $old) {
                if (basename($old) !== $name) {
                    @unlink($old);
                }
            }

            return $name;
        } catch (\Throwable $e) {
            Log::add('Thumbnail for ' . $rel . ' failed: ' . $e->getMessage(), Log::WARNING, 'mod_pdfflipbook');
            @file_put_contents($dir . '/' . $name . '.error', $e->getMessage());

            return null;
        }
    }

    /**
     * Unicode vereinheitlichen (macOS speichert „é“ als e + Akzent).
     */
    public static function nfc(string $s): string
    {
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);

            if ($n !== false) {
                return $n;
            }
        }

        return $s;
    }
}
