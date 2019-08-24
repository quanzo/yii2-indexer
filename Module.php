<?php

namespace x51\yii2\modules\indexer;

use \DateTime;
use \x51\yii2\modules\indexer\models\Indexer;
use \Yii;
use \yii\helpers\Url;

class Module extends \yii\base\Module
{
    const EVENT_BEFORE_INDEX = 'beforIndexed';
    const EVENT_BEFORE_SEARCH = 'beforSearch';
    const EVENT_START_REFRESH_INDEX = 'startRefreshIndex';

    public $ttl = 86400;
    public $exclude; // роуты в которых запрещено использование. Можно применять символы ? и * Или задать callback
    public $layoutRule = '*/layouts/*';
    public $saveOrigContent = true;
    public $saveOrigTitle = true;
    protected $_removeWords = [ // слова для удаления из поискового контента
        /*'из', 'в', 'под', 'если', 'то', 'из',
    'что', 'c', 'и', 'подробнее'*/
    ];
    protected $_queryRemoveParams = [ // параметры для удаления из адреса страницы
        'r', '_pjax', 'ajax'
    ];
    public $fullpageMode = false;
    public $defaultPageSize = 15;
    public $defaultSnippetSize = 300;
    public $snippetFromBegin = true;
    public $enableHashtags = true; // обрабатывать теги #
    public $notShowOld = false; // не показывать устаревшие записи
    public $processIfModifiedSince = true; // отработать If-Modified-Since. При индексировании, ищет http заголовок с датой документа и использует ее в индексировании.

    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = '\x51\yii2\modules\indexer\controllers';

    /**
     * {@inheritdoc}
     */
    public $defaultController = 'default';

    public function init()
    {
        parent::init();
        $view = Yii::$app->view;
        if (!$this->fullpageMode) {
            $view->on($view::EVENT_AFTER_RENDER, [$this, 'onAfterRender']);
        } else {
            $response = \Yii::$app->response;
            $response->on($response::EVENT_AFTER_PREPARE, [$this, 'onAfterPrepareResponse']);
        }

        if (!isset($this->module->i18n->translations['module/indexer'])) {
            $this->module->i18n->translations['module/indexer'] = [
                'class' => '\yii\i18n\PhpMessageSource',
                'basePath' => __DIR__ . '/messages',
                'sourceLanguage' => 'en-US',
                'fileMap' => [
                    'module/indexer' => 'messages.php',
                ],
            ];
        }
    }

    public function setRemoveWords(array $words)
    {
        $this->_removeWords = $words;
    }

    public function getRemoveWords()
    {
        return $this->_removeWords;
    }

    public function setQueryRemoveParams(array $words)
    {
        $this->_queryRemoveParams = $words;
    }

    public function getQueryRemoveParams()
    {
        return $this->_queryRemoveParams;
    }

    /**
     * Обработка события \yii\web\View::EVENT_AFTER_RENDER
     *
     * @param [type] $event
     * @return void
     */
    public function onAfterRender($event)
    {
        //$event->viewFile;$event->params;$output;$isValid = true;
        //echo '<pre>';print_r($event->viewFile);echo "\r\n";print_r($event->params);echo '</pre>';
        $request = Yii::$app->request;
        $url = $this->url();
        $lastModified = $this->getLastModified();

        $ifValid = isset($event->params['content']) && $this->ifPossible();

        if ($ifValid && $this->layoutRule) {
            $ok = true;
            if (is_string($this->layoutRule)) {
                $ok = fnmatch($this->layoutRule, $event->viewFile);
            } elseif (is_callable($this->layoutRule)) {
                $f = $this->layoutRule;
                $ok = $f($event->viewFile);
            }
            $ifValid &= $ok;
        }

        if ($ifValid) {
            $view = Yii::$app->view;
            $title = $view instanceof yii\web\View ? $view->title : '';
            if ($lastModified) {
                $this->refresh($this->url(), $title, $event->params['content'], $lastModified);
            } else {
                $this->refresh($this->url(), $title, $event->params['content']);
            }
            if ($this->enableHashtags) {
                $event->output = $this->processHashtags($event->output);
            }
            // отработка заголовка IF_MODIFIED_SINCE
            if ($lastModified) {
                $ifModifiedSince = false;
                if (isset($_ENV['HTTP_IF_MODIFIED_SINCE'])) {
                    $ifModifiedSince = strtotime(substr($_ENV['HTTP_IF_MODIFIED_SINCE'], 5));
                } elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
                    $ifModifiedSince = strtotime(substr($_SERVER['HTTP_IF_MODIFIED_SINCE'], 5));
                }
                if ($ifModifiedSince && $ifModifiedSince >= $lastModified->getTimeStamp()) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
                    exit;
                }
            }
        }
    } // end onAfterRender

    /**
     * Обработка события
     *
     * @param [type] $event
     * @return void
     */
    public function onAfterPrepareResponse($event)
    {
        $ifValid = $this->ifPossible();
        $response = $event->sender;
        if ($ifValid) {
            if ($response->stream === null) { // отдача контента, а не файла
                $view = Yii::$app->view;
                $title = $view instanceof yii\web\View ? $view->title : '';
                $this->refresh($this->url(), $title, $response->content);
                if ($this->enableHashtags) {
                    $response->content = $this->processHashtags($response->content);
                }
            }
        }

    } // end onAfterPrepareResponse

    /**
     * Подготовка поискового контента перед сохранением в базу.
     * Удаляются теги и лишние символы. Текст приводится к нижнему регистру.
     *
     * @param string $content
     * @return string
     */
    public function prepareContent($content)
    {
        $buff = $this->removeWords(
            mb_strtolower(
                trim(
                    str_replace(
                        ["\x0B", "\0", "\r", "\n", "\t", '.', ',', ';', ':', '?', '!', '(', ')', '[', ']', '{', '}', '"', "'", '`', '|', '      ', '     ', '    ', '   ', '  '],
                        ' ',
                        $this->stripTags(
                            str_replace(['>'], ['> '], $content)
                        )
                    )
                )
            ),
            $this->_removeWords
        );
        return $buff;
    } // end prepareContent

    /**
     * Подготовка сниппета. Для вывода в результатах поиска.
     * Удаляются теги, переводы строки, двойные пробелы и т.п.
     *
     * @param string  $content
     * @param integer $count
     * @return string
     */
    public function prepareSnippet($content, $count = 300, $searchStr = false)
    {
        $buff = trim(
            str_replace(
                ["\x0B", "\0", '      ', '     ', '    ', '   ', '  '],
                ' ',
                $this->stripTags(
                    str_replace(
                        ['>', "\r", "\t", '</'],
                        ['> ', "\n", ' ', "\n</"],
                        $this->stripTags($content)
                    )
                )
            )
        );
        $buff = str_replace(
            ["\n ", "\n \n", "\n  \n", "\n\n\n\n", "\n\n\n", "\n\n"],
            ["\n", "\n", "\n", "\n", "\n", "\n"],
            $buff
        );

        return $this->textFragment($buff, $count, $searchStr);
    }

    /**
     * Возвращает из текста фрагмент определенной длинны
     *
     * @param string $content
     * @param integer $count
     * @param boolean|string $searchStr
     * @return string
     */
    public function textFragment($content, $count = 300, $searchStr = false)
    {
        if ($searchStr && $count) {
            // найдем первое вхождение
            $ss_pos = mb_strpos($content, $searchStr);
            if ($ss_pos !== false) {
                $pp_count = floor($count / 2);
                $start = $ss_pos - $pp_count;
                if ($start < 0) {
                    $start = 0;
                }
                return mb_substr($content, $start, $count);
            }
        }
        if ($count > 0) {
            return mb_substr($content, 0, $count);
        }
        return $content;
    } // end textFragment

    /**
     * Подготовка поисковой строки
     *
     * @param string $content
     * @return string
     */
    public function prepareSearchStr($content)
    {
        $buff = mb_strtolower(
            trim(
                str_replace(
                    ["\x0B", "\0", "\r", "\n", "\t", '.', ',', ';', ':', '?', '!', '[', ']', '{', '}', "'", '`', '|', '      ', '     ', '    ', '   ', '  '],
                    ' ',
                    strip_tags(
                        str_replace(['>'], ['> '], $content)
                    )
                )
            )
        );
        return $buff;
    }

    /**
     * Обновляет поисковые данные
     *
     * @param string $url
     * @param string $notPreparedTitle
     * @param string $notPrepareContent
     * @return void
     */
    public function refresh($url, $notPreparedTitle, $notPrepareContent, DateTime $refreshChangeDate = null)
    {
        // startRefresh
        $startEvent = new \x51\yii2\modules\indexer\events\StartRefreshIndexEvent();
        $startEvent->module = $this;
        $startEvent->url = $url;
        $startEvent->title = $notPreparedTitle;
        $startEvent->content = $notPrepareContent;
        $this->trigger(self::EVENT_START_REFRESH_INDEX, $startEvent);
        if ($startEvent->isValid) {
            $query = Indexer::find();
            $result = $query->where(['url' => $url])->one();
            $timestamp = time();
            $ifNeedRefresh = false;
            if ($result) {
                $cdt = \DateTime::createFromFormat('Y-m-d H:i:s', $result->change_date);
                $change_date = $cdt->getTimestamp();
                if ($refreshChangeDate) { // задана дата
                    if ($refreshChangeDate->getTimeStamp() != $change_date) {
                        $ifNeedRefresh = true;
                    }
                } else {
                    if ($timestamp > $change_date + $this->ttl) { // обновить
                        $ifNeedRefresh = true;
                    }
                }
            } else {
                $ifNeedRefresh = true;
                $result = new Indexer();
            }
            if ($ifNeedRefresh) {
                $result->url = $url;
                $result->orig_title = $this->saveOrigTitle ? $startEvent->title : '';
                $result->title = $this->prepareContent($startEvent->title);
                $result->orig_content = $this->saveOrigContent ? $startEvent->content : '';
                $result->content = $this->prepareContent($startEvent->content);
                $result->snippet = $this->prepareSnippet($startEvent->content, false);
                $result->attrs = '';
                $result->role = '';
                if ($refreshChangeDate) { // задана дата
                    $result->ttl = date('Y-m-d H:i:s', $refreshChangeDate->getTimeStamp() + $this->ttl);
                    $result->change_date = date('Y-m-d H:i:s', $refreshChangeDate->getTimeStamp());
                } else {
                    $result->ttl = date('Y-m-d H:i:s', $timestamp + $this->ttl);
                    $result->change_date = date('Y-m-d H:i:s', $timestamp);
                }

                // beforeIndex
                $event = new \x51\yii2\modules\indexer\events\BeforeIndexEvent();
                $event->module = $this;
                $event->model = $result;
                $this->trigger(self::EVENT_BEFORE_INDEX, $event);
                if ($event->isValid) {
                    $event->model->save();
                }
            }
        }
    } // end refresh

    /**
     * По url возвращает сохраненную запись
     *
     * @param string $url
     * @return \x51\yii2\modules\indexer\models\Indexer
     */
    public function getIndex($url)
    {
        $query = Indexer::find();
        return $query->where(['url' => $url])->one();
    } // end get

    /**
     * Помечает результат индексирования, как устаревший
     *
     * @param string $url
     * @return void
     */
    public function markOld($url)
    {
        $rec = $this->getIndex($url);
        if ($rec) {
            $rec->ttl = date('Y-m-d H:i:s', time() - 1);
            $rec->save();
        }
    }

    /**
     * Поиск
     *
     * @param string $text
     * @param int|boolean $perpage
     * @param integer $page
     * @param integer $count
     * @return array
     */
    public function search($text, $perpage = false, $page = 1, &$count = false)
    {
        $query = Indexer::find();

        // beforeSearch
        $event = new \x51\yii2\modules\indexer\events\BeforeSearchEvent();
        $event->module = $this;
        $event->origSearchStr = $text;
        $event->preparedSearchStr = $this->prepareSearchStr($text);
        $event->role = '';
        $this->trigger(self::EVENT_BEFORE_SEARCH, $event);

        //$searchStr = $this->prepareSearchStr($text);
        $page = intval($page);
        if ($page < 1) {
            $page = 1;
        }

        if (Yii::$app->db->driverName === 'mysql') {
            $match = 'MATCH(title, content) AGAINST("' . $event->preparedSearchStr . '" IN BOOLEAN MODE)';
            $query->select(['*', $match . ' AS score'])
                ->where($match)
                ->orderBy(['score' => SORT_DESC]);
        } else {
            $likeStr = str_replace(' ', '%', $event->preparedSearchStr);
            $query->select(['*'])->where(
                [
                    'or',
                    ['like', 'title', $likeStr],
                    ['like', 'content', $likeStr],
                ]
            );
        }
        if ($this->notShowOld) {
            $query->andWhere(['>=', 'ttl', date('Y-m-d H:i:s')]);
        }
        $query->andWhere(['role' => $event->role]);
        if ($perpage) {
            if ($count === false) {
                $count = $query->count();
            }
            $pages = floor($count / $perpage) + (fmod($count, $perpage) > 0 ? 1 : 0);
            if ($page > $pages) {
                $page = $pages;
            }
            $query->limit($perpage)
                ->offset(($page - 1) * $perpage);
        }
        $result = $query->all();
        return $result;
    }

    /**
     * Формирует url для его вывода в результатах поиска
     *
     * @param string $url
     * @return string
     */
    public function actualUrl($url)
    {
        // разделим url на 2 части
        $qpos = strpos($url, '?');
        if ($qpos) {
            $left = substr($url, 0, $qpos);
            $right = substr($url, $qpos + 1);
            $arUrl = [];
            if ($right) {
                parse_str($right, $arUrl);
            }
            $arUrl[0] = $left;
        } else {
            $arUrl = [$url];
        }
        return Url::to($arUrl);
    } // actualUrl

    /**
     * Удаление слов из текста
     *
     * @param string $content
     * @return void
     */
    public function removeWords($content, array $words = [])
    {
        if ($words) {
            foreach ($words as $word) {
                $content = preg_replace('/\s' . $word . '([\s\.,?!])/imu', '$1', $content);
            }
        }
        return $content;
    }

    /**
     * Очистить контент от тегов
     *
     * @param string $content
     * @return string
     */
    public function stripTags($content)
    {
        return strip_tags(
            preg_replace([
                '/<script[^>]*>.*?<\/script>/is',
                '/<style[^>]*>.*?<\/style>/is',
            ], '', $content)
        );
    }

    /**
     * Заменяет хештеги на ссылки на поиск
     *
     * @param string $content
     * @return string
     */
    public function processHashtags($content)
    {
        $mask = '/#([^\b#@<>\/"\'\s]+)/u';
        $hashtagUrlPattern = Url::to(['/' . $this->id . '/default/index', 'search' => '#hashtag']);

        //preg_replace_callback()

        $startOffsetPos = 0;
        $bodyPos = mb_stripos($content, '<body');
        if ($bodyPos !== false) {
            $startOffsetPos = $bodyPos;
        }
        $offsetPos = $startOffsetPos;

        // первое - найти хештеги в контенте без html (для того, чтобы исключить якоря)
        $arMatches = [];
        $arHashtags = [];
        if (preg_match_all($mask, $content, $arMatches, PREG_OFFSET_CAPTURE)) {
            // хеш теги есть
            $contentSize = strlen($content);

            // проверим совпадения на корректность
            $arIgnored = [];
            foreach ([
                ['href="', '"'],
                ['<script', '</script>'],
                ['<style', '</style>'],
                ['<', '>']
            ] as $part) {
                for ($i = 0; $i < sizeof($arMatches[0]); $i++) {
                    if (!isset($arIgnored[$i]) || !$arIgnored[$i]) {
                        $matchPos = $arMatches[0][$i][1];
                        $matchPosNegative = ($contentSize - $matchPos - 1) * -1;
                        $tag = $arMatches[1][$i][0];
                        //echo "tag = $tag matchPos = $matchPos $matchPosNegative";
                        $ignore = $matchPos < $startOffsetPos;
                        if (!$ignore) { // проверка на якорь ссылки
                            $p1 = strripos($content, $part[0], $matchPosNegative);
                            //echo "p1 = $p1\r\n<br>";
                            if ($p1 !== false) {
                                $p2 = strpos($content, $part[1], $p1 + strlen($part[0]));
                                //echo "p2 = $p2\r\n<br>";

                                $ignore = $matchPos < $p2;
                                //echo mb_substr($content, $p1, 82)."<br>";
                            }
                        }
                        $arIgnored[$i] = $ignore;
                    }
                }
            }
            //var_dump($arIgnored);

            $matchesCounter = 0;

            return preg_replace_callback($mask, function ($repMatches) use ($arIgnored, &$matchesCounter, $hashtagUrlPattern) {
                $ignore = $arIgnored[$matchesCounter];

                ++$matchesCounter;
                if ($ignore) {
                    return $repMatches[0];
                } else {
                    return '<a target="_blank" href="' . str_replace('%23hashtag', urlencode(strip_tags(trim('#' . $repMatches[1]))), $hashtagUrlPattern) . '" class="hashtag">#' . trim($repMatches[1]) . '</a> ';
                }
            }, $content);

            //$arHashtags = array_unique($arMatches[1]);
        }
        // 2 - заменить хештеги на ссылку
        /*if ($arHashtags) {
        $arReplaceParts = [];
        foreach ($arHashtags as $ht) {
        $arReplaceParts['#' . $ht] = '<a target="_blank" href="' . str_replace('%23hashtag', urlencode(strip_tags(trim('#' . $ht))), $hashtagUrlPattern) . '" class="hashtag">#' . trim($ht) . '</a> ';
        }
        return strtr($content, $arReplaceParts);
        }*/
        return $content;
    } // end processTags

    /**
     * Формирует url для базы данных
     *
     * @return string
     */
    protected function url()
    {
        $request = Yii::$app->request;
        $url = '/' . Yii::$app->controller->route;
        $qString = $request->queryString;
        if ($qString) {
            $arParams = [];
            parse_str($qString, $arParams);
            if ($this->_queryRemoveParams) {
                foreach ($this->_queryRemoveParams as $p) {
                    unset($arParams[$p]);
                }
            }            
            if ($arParams) {
                $url .= '?' . http_build_query($arParams);
            }
        }
        //$url = $request->url;
        return $url;
    }

    /**
     * Возможно ли сохранение страницы
     *
     * @return boolean
     */
    protected function ifPossible()
    {
        $request = Yii::$app->request;
        $url = $this->url();

        $ifValid = $request->isGet
        && strpos($url, '/' . $this->id . '/') !== 0
        && strpos($url, '/debug/') !== 0;

        if ($ifValid) {
            $excluded = false;
            if (!empty($this->exclude)) {
                if (!is_array($this->exclude)) {
                    $arExclude = [$exclude];
                } else {
                    $arExclude = &$this->exclude;
                }
                foreach ($arExclude as $exPath) {
                    if (is_string($exPath)) {
                        $excluded = fnmatch($exPath, $url);
                    } elseif (is_callable($exPath)) {
                        $excluded = $exPath($url);
                    }
                    if ($excluded) {
                        break;
                    }
                }
            }
            if (!$excluded) {
                return true;
            }
        }
        return false;
    } // end ifPossible

    protected function getLastModified()
    {
        if (function_exists('headers_list')) {
            $arHeaders = headers_list();
            foreach ($arHeaders as $header) {
                if (strpos($header, 'Last-Modified:') === 0) {
                    try {
                        return DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', trim(substr($header, 14)));
                    } catch (\Exception $e) {
                        return false;
                    }
                }
            }
        }
        return false;
    }

} // end class
