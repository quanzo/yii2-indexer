<?php

namespace x51\yii2\modules\indexer;

use \DateTime;
use \x51\yii2\modules\indexer\models\Indexer;
use \Yii;
use \yii\helpers\Url;

class Module extends \yii\base\Module
{
    const EVENT_BEFORE_INDEX = 'beforIndexed';

    public $ttl = 86400;
    public $exclude; // роуты в которых запрещено использование. Можно применять символы ? и *
    public $layoutRule = '*/layouts/*';
    public $saveOrigContent = true;
    public $saveOrigTitle = true;
    protected $_removeWords = [
        /*'из', 'в', 'под', 'если', 'то', 'из',
    'что', 'c', 'и'*/
    ];
    public $fullpageMode = false;
    public $defaultPageSize = 15;

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
        $this->_removeWords = array_merge($this->_removeWords, $words);
    }

    public function getRemoveWords()
    {
        return $this->_removeWords;
    }

    public function onAfterRender($event)
    {
        //$event->viewFile;$event->params;$output;$isValid = true;
        //echo '<pre>';print_r($event->viewFile);echo "\r\n";print_r($event->params);echo '</pre>';
        $request = Yii::$app->request;
        $url = $this->url();

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
            $this->refresh($this->url(), $title, $event->params['content']);
        }
    } // end onAfterRender

    public function onAfterPrepareResponse($event)
    {
        $ifValid = $this->ifPossible();
        if ($ifValid) {
            $response = $event->sender;
            if ($response->stream === null) { // отдача контента, а не файла
                $view = Yii::$app->view;
                $title = $view instanceof yii\web\View ? $view->title : '';
                $this->refresh($this->url(), $title, $response->content);
            }
        }
    } // end onAfterPrepareResponse

    /**
     * Подготовка поискового контента перед сохранением в базу.
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
                        ["\x0B", "\0", "\r", "\n", "\t", '.', ',', ';', ':', '?', '!', '(', ')', '[', ']', '#', '{', '}', '"', "'", '`', '|', '      ', '     ', '    ', '   ', '  '],
                        ' ',
                        $this->stripTags(
                            str_replace(['>'], ['> '], $content)
                        )
                    )
                )
            )
        );
        return $buff;
    } // end prepareContent

    /**
     * Подготовка сниппета. Для вывода в результатах поиска.
     *
     * @param string  $content
     * @param integer $count
     * @return string
     */
    public function prepareSnippet($content, $count = 300)
    {
        $buff = trim(
            str_replace(
                ["\x0B", "\0", '      ', '     ', '    ', '   ', '  '],
                ' ',
                $this->stripTags(
                    str_replace(
                        ['>', "\r", "\t", '</'],
                        ['> ', "\n", ' ', "\n</"],
                        $content
                    )
                )
            )
        );
        $buff = str_replace(
            ["\n ", "\n \n", "\n  \n", "\n\n\n\n", "\n\n\n", "\n\n"],
            ["\n", "\n", "\n", "\n", "\n", "\n"],
            $buff
        );
        return mb_substr($buff, 0, $count);
    }

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
                    ["\x0B", "\0", "\r", "\n", "\t", '.', ',', ';', ':', '?', '!', '[', ']', '#', '{', '}', "'", '`', '|', '      ', '     ', '    ', '   ', '  '],
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
    public function refresh($url, $notPreparedTitle, $notPrepareContent)
    {
        $query = Indexer::find();
        $result = $query->where(['url' => $url])->one();
        $timestamp = time();
        $ifNeedRefresh = false;
        if ($result) {
            $cdt = \DateTime::createFromFormat('Y-m-d H:i:s', $result->change_date);
            $change_date = $cdt->getTimestamp();

            if ($timestamp > $change_date + $this->ttl) { // обновить
                $ifNeedRefresh = true;
            }
        } else {
            $ifNeedRefresh = true;
            $result = new Indexer();
        }
        if ($ifNeedRefresh) {
            $result->url = $url;
            $result->orig_title = $this->saveOrigTitle ? $notPreparedTitle : '';
            $result->title = $this->prepareContent($notPreparedTitle);
            $result->orig_content = $this->saveOrigContent ? $notPrepareContent : '';
            $result->content = $this->prepareContent($notPrepareContent);
            $result->ttl = date('Y-m-d H:i:s', $timestamp + $this->ttl);
            $result->change_date = date('Y-m-d H:i:s', $timestamp);

            // beforeIndex
            $event = new \x51\yii2\modules\indexer\events\BeforeIndexEvent();
            $event->module = $this;
            $event->model = $result;
            $this->trigger(self::EVENT_BEFORE_INDEX, $event);
            if ($event->isValid) {
                $event->model->save();
            }
        }
    } // end refresh

    /**
     * По url возвращает сохраненную запись
     *
     * @param string $url
     * @return \x51\yii2\modules\indexer\models\Indexer
     */
    public function getIndexContent($url)
    {
        $query = Indexer::find();
        return $query->where(['url' => $url])->one();
    } // end get

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
        $searchStr = $this->prepareSearchStr($text);
        $page = intval($page);
        if ($page < 1) {
            $page = 1;
        }

        if (Yii::$app->db->driverName === 'mysql') {
            $match = 'MATCH(title, content) AGAINST("' . $searchStr . '" IN BOOLEAN MODE)';
            $query->select(['*', $match . ' AS score'])
                ->where($match)
                ->orderBy(['score' => SORT_DESC]);
        } else {
            $likeStr = str_replace(' ', '%', $searchStr);
            $query->select(['*'])->where(
                [
                    'or',
                    ['like', 'title', $likeStr],
                    ['like', 'content', $likeStr],
                ]
            );
        }
        if ($perpage) {
            if ($count === false) {
                $count = $query->count();
            }
            $pages = floor($count/$perpage)+(fmod($count, $perpage) > 0 ? 1 : 0);
            if ($page>$pages) {
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
    public function removeWords($content)
    {
        if ($this->_removeWords) {
            foreach ($this->_removeWords as $word) {
                $content = preg_replace('/\s' . $word . '([\s\.,?!])/imu', '$1', $content);
            }
        }
        return $content;
    }

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
            $url .= '?' . $qString;
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
    }

    /**
     * Очистить контент от тегов
     *
     * @param string $content
     * @return string
     */
    protected function stripTags($content)
    {
        return strip_tags(
            preg_replace([
                '/<script[^>]*>.*?<\/script>/is',
                '/<style[^>]*>.*?<\/style>/is',
            ], '', $content)
        );
    }

} // end class
