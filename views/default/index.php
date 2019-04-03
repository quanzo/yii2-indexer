<?php
use \Yii;
use yii\widgets\Pjax;
use yii\widgets\ActiveForm;
use yii\helpers\Html;
use yii\widgets\LinkPager;





$formId = 'search-form';
$module = $this->context->module;
$this->title = Yii::t('module/indexer', 'Search');
$this->params['breadcrumbs'][] = $this->title;


?>
<div class="search-index">
    <h1><?= Html::encode($this->title) ?></h1>
    <?php Pjax::begin(['id' => 'search-index']);?>
    <?php
        $form = ActiveForm::begin([
            'options' => [
                'data-pjax' => true,
                'id' => $formId,
            ],
        ]);
    ?>    
    <div class="search-line">
    <?=Html::textInput('search', !empty($SEARCH) ? $SEARCH : '', ['class' => 'search-input']);?>
    <?=Html::submitButton('🔍', ['class' => 'search-btn'])?>
    </div>

    <?php $form::end();?>


    <?php
        if ($LIST !== false) {
            if ($LIST) { // вывод результатов
                echo '<ul>';
                foreach ($LIST as $element) {
                    echo '<li>';                    
                    echo '<a href="'.$module->actualUrl($element->url).'" target="_blank" class="caption">';
                    if (!empty($element->orig_title)) {
                        echo $element->orig_title;
                    } else {
                        echo $element->title;
                    }                    
                    echo '</a>';
                    echo '<div class="snippet">';
                    echo $module->prepareSnippet($element->orig_content, $module->defaultSnippetSize, $SEARCH).'...';
                    echo '<a href="'.$module->actualUrl($element->url).'" target="_blank" class="more">';
                    echo 'Подробнее';
                    echo '</a>';
                    echo '</div>';
                    
                    echo '</li>';                    
                }
                echo '</ul>';

                if (!empty($PAGES)) {
                    echo LinkPager::widget(['pagination' => $PAGES]);
                }
            } else { // ничего не найдено
                echo '<div class="not-found">'.Yii::t('module/indexer', 'Not found').'</div>';
            }
    ?>
    <?php } ?>
    
    <?php Pjax::end();?>
</div>
