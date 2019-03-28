<?php
namespace x51\yii2\modules\indexer\controllers;

use \Yii;
use \yii\data\Pagination;
use \yii\web\Controller;

class DefaultController extends Controller
{
    /**
     * Поиск
     *
     * @return string
     */
    public function actionIndex($search = false)
    {
        $pageParam = 'page';
        $pageSizeParam = 'per-page';
        $totalCount = false;
        $searchResult = false;
        $PAGES = false;

        $request = Yii::$app->request;
        $params = $request instanceof \yii\web\Request ? $request->getQueryParams() : [];

        $currPageSize = !empty($params[$pageSizeParam]) ? intval($params[$pageSizeParam]) : $this->module->defaultPageSize;
        $currPage = !empty($params[$pageParam]) ? intval($params[$pageParam]) : 1;

        if ($request->isPost) {
            $search = $request->post('search');
        }
        if ($search) {
            $searchResult = $this->module->search($search, $currPageSize, $currPage, $totalCount);
            $PAGES = new Pagination([
                'defaultPageSize' => $this->module->defaultPageSize,
                'pageParam' => $pageParam,
                'pageSizeParam' => $pageSizeParam,
                'totalCount' => $totalCount,
                'page' => $currPage - 1,
                'pageSize' => $currPageSize,
            ]);
        }

        return $this->render('index', [
            'LIST' => $searchResult,
            'SEARCH' => $this->module->prepareContent($search),
            'PAGES' => $PAGES,
        ]);
    } // end actionIndex

} // end SearchController
