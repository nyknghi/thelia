<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Thelia\Action;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\Translation\TranslationEvent;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;

/**
 * Class Translation
 * @package Thelia\Action
 * @author Manuel Raynaud <manu@thelia.net>
 */
class Translation extends BaseAction implements EventSubscriberInterface
{
    public function getTranslatableStrings(TranslationEvent $event)
    {
        $stringCount = $this->walkDir(
            $event->getDirectory(),
            $event->getMode(),
            $event->getLocale(),
            $event->getDomain(),
            $strings
        );

        $event
            ->setTranslatableStrings($strings)
            ->setTranslatableStringCount($stringCount)
            ;
    }
    /**
     * Recursively examine files in a directory tree, and extract translatable strings.
     *
     * Returns an array of translatable strings, each item having with the following structure:
     * 'files' an array of file names in which the string appears,
     * 'text' the translatable text
     * 'translation' => the text translation, or an empty string if none available.
     * 'dollar'  => true if the translatable text contains a $
     *
     * @param  string $directory the path to the directory to examine
     * @param  string $walkMode type of file scanning: WALK_MODE_PHP or WALK_MODE_TEMPLATE
     * @param  string $currentLocale the current locale
     * @param  string $domain the translation domain (fontoffice, backoffice, module, etc...)
     * @param  array $strings the list of strings
     * @throws \InvalidArgumentException if $walkMode contains an invalid value
     * @return number the total number of translatable texts
     */
    protected function walkDir($directory, $walkMode, $currentLocale, $domain, &$strings)
    {
        $numTexts = 0;

        if ($walkMode == TranslationEvent::WALK_MODE_PHP) {
            $prefix = '\-\>[\s]*trans[\s]*\([\s]*';

            $allowedExts = array('php');
        } elseif ($walkMode == TranslationEvent::WALK_MODE_TEMPLATE) {
            $prefix = '\{intl(?:.*?)l=[\s]*';

            $allowedExts = array('html', 'tpl', 'xml', 'txt');
        } else {
            throw new \InvalidArgumentException(
                Translator::getInstance()->trans(
                    'Invalid value for walkMode parameter: %value',
                    array('%value' => $walkMode)
                )
            );
        }

        try {
            Tlog::getInstance()->debug("Walking in $directory, in mode $walkMode");

            /** @var \DirectoryIterator $fileInfo */
            foreach (new \DirectoryIterator($directory) as $fileInfo) {
                if ($fileInfo->isDot()) {
                    continue;
                }

                if ($fileInfo->isDir()) {
                    $numTexts += $this->walkDir(
                        $fileInfo->getPathName(),
                        $walkMode,
                        $currentLocale,
                        $domain,
                        $strings
                    );
                }

                if ($fileInfo->isFile()) {
                    $ext = $fileInfo->getExtension();

                    if (in_array($ext, $allowedExts)) {
                        if ($content = file_get_contents($fileInfo->getPathName())) {
                            $short_path = $this->normalizePath($fileInfo->getPathName());

                            Tlog::getInstance()->debug("Examining file $short_path\n");

                            $matches = array();

                            if (preg_match_all(
                                '/'.$prefix.'((?<![\\\\])[\'"])((?:.(?!(?<![\\\\])\1))*.?)*?\1/ms',
                                $content,
                                $matches
                            )) {
                                Tlog::getInstance()->debug("Strings found: ", $matches[2]);

                                $idx = 0;

                                foreach ($matches[2] as $match) {
                                    $hash = md5($match);

                                    if (isset($strings[$hash])) {
                                        if (! in_array($short_path, $strings[$hash]['files'])) {
                                            $strings[$hash]['files'][] = $short_path;
                                        }
                                    } else {
                                        $numTexts++;

                                        // remove \' (or \"), that will prevent the translator to work properly, as
                                        // "abc \def\" ghi" will be passed as abc "def" ghi to the translator.

                                        $quote = $matches[1][$idx];

                                        $match = str_replace("\\$quote", $quote, $match);

                                        // Ignore empty strings
                                        if (strlen($match) == 0) {
                                            continue;
                                        }

                                        $strings[$hash] = array(
                                            'files'   => array($short_path),
                                            'text'  => $match,
                                            'translation' => Translator::getInstance()->trans(
                                                $match,
                                                [],
                                                $domain,
                                                $currentLocale,
                                                false
                                            ),
                                            'dollar'  => strstr($match, '$') !== false
                                        );
                                    }

                                    $idx++;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\UnexpectedValueException $ex) {
            // Directory does not exists => ignore it.
        }

        return $numTexts;
    }

    public function writeTranslationFile(TranslationEvent $event)
    {
        $file = $event->getTranslationFilePath();

        $fs = new Filesystem();

        if (! $fs->exists($file) && true === $event->isCreateFileIfNotExists()) {
            $dir = dirname($file);

            if (! $fs->exists($file)) {
                $fs->mkdir($dir);
            }
        }

        if ($fp = @fopen($file, 'w')) {
            fwrite($fp, '<' . "?php\n\n");
            fwrite($fp, "return array(\n");

            $texts = $event->getTranslatableStrings();
            $translations = $event->getTranslatedStrings();

            // Sort keys alphabetically while keeping index
            asort($texts);

            foreach ($texts as $key => $text) {
                // Write only defined (not empty) translations
                if (! empty($translations[$key])) {
                    $text = str_replace("'", "\'", $text);

                    $translation = str_replace("'", "\'", $translations[$key]);

                    fwrite($fp, sprintf("    '%s' => '%s',\n", $text, $translation));
                }
            }

            fwrite($fp, ");\n");

            @fclose($fp);
        } else {
            throw new \RuntimeException(
                Translator::getInstance()->trans(
                    'Failed to open translation file %file. Please be sure that this file is writable by your Web server',
                    array('%file' => $file)
                )
            );
        }
    }

    protected function normalizePath($path)
    {
        $path = str_replace(
            str_replace('\\', '/', THELIA_ROOT),
            '',
            str_replace('\\', '/', realpath($path))
        );

        return ltrim($path, '/');
    }

    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::TRANSLATION_GET_STRINGS => array('getTranslatableStrings', 128),
            TheliaEvents::TRANSLATION_WRITE_FILE => array('writeTranslationFile', 128)
        );
    }
}
